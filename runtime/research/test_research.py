import base64,hashlib,io,json,os,shutil,unittest,zipfile
from unittest.mock import patch
from fastapi.testclient import TestClient
from pypdf import PdfReader
from docx import Document
from .exports import export,STYLES
from .server import app
from .sandbox import execute,cancel

def fixture():
 return {'format':'docx','draft':True,'settings':{'citation_style':'apa'},'sources':[{'id':7,'title':'Synthetic academic fixture','authors':[{'given':'Test','family':'Fixture'}],'year':2025,'doi':'10.1234/fixture'}],'document':{'type':'doc','content':[{'type':'heading','attrs':{'level':1,'id':'intro'},'content':[{'type':'text','text':'Research fixture'}]},{'type':'paragraph','content':[{'type':'text','text':'Synthetic evidence supports a test claim.'},{'type':'citation','attrs':{'sourceId':7}},{'type':'math','attrs':{'latex':r'E=mc^2'}},{'type':'footnote','attrs':{'text':'A real exported footnote.'}}]},{'type':'table','content':[{'type':'tableRow','content':[{'type':'tableHeader','content':[{'type':'paragraph','content':[{'type':'text','text':'Metric'}]}]}]},{'type':'tableRow','content':[{'type':'tableCell','content':[{'type':'paragraph','content':[{'type':'text','text':'Actual test fixture'}]}]}]}]}]}}
class WorkerTest(unittest.TestCase):
 def test_five_exports_reopen_with_math_tables_footnotes(self):
  for fmt in ['docx','pdf','tex','bib','md']:
   with self.subTest(format=fmt):
    p=fixture();p['format']=fmt;r=export(p);b=base64.b64decode(r['data']);self.assertEqual(r['sha256'],hashlib.sha256(b).hexdigest())
    if fmt=='docx':
     d=Document(io.BytesIO(b));self.assertEqual(len(d.tables),1)
     with zipfile.ZipFile(io.BytesIO(b))as z:self.assertIn(b'm:oMath',z.read('word/document.xml'));self.assertIn('word/footnotes.xml',z.namelist())
    elif fmt=='pdf':self.assertIn('Synthetic evidence',''.join(p.extract_text() for p in PdfReader(io.BytesIO(b)).pages))
    elif fmt=='tex':
     with zipfile.ZipFile(io.BytesIO(b))as z:self.assertIn(b'\\begin{document}',z.read('manuscript.tex'))
    else:self.assertIn(b'Fixture',b)
 def test_all_six_styles_are_real_csl_conversions(self):
  for style in STYLES:
   p=fixture();p['format']='md';p['settings']['citation_style']=style;text=base64.b64decode(export(p)['data']).decode();self.assertIn('synthetic academic fixture',' '.join(text.lower().split()))
 def test_unresolved_citation_and_unsafe_equations_rejected(self):
  p=fixture();p['sources']=[]
  with self.assertRaises(ValueError):export(p)
  p=fixture();p['document']['content'][1]['content'][2]['attrs']['latex']=r'\input{/etc/passwd}'
  with self.assertRaises(ValueError):export(p)
 def test_authenticated_broker_pdf_and_body_limit_with_mocked_container_transport(self):
  with patch.dict(os.environ,{'RESEARCH_WORKER_KEY':'a-test-secret-with-at-least-32-characters'}):
   client=TestClient(app);self.assertEqual(client.get('/health').status_code,401);headers={'Authorization':'Bearer a-test-secret-with-at-least-32-characters'};p=fixture();p['format']='pdf';pdf=export(p)
   from .documents import extract
   with patch('runtime.research.server.processing',side_effect=lambda op,payload:extract(payload)):
    r=client.post('/extract',headers=headers,json={'data':pdf['data']});self.assertEqual(r.status_code,200);self.assertIn('Synthetic evidence',r.json()['text'])
   self.assertEqual(client.post('/extract',headers=headers,content=b'x'*24_000_001).status_code,413)
 def test_mutable_images_rejected_without_execution(self):
  with patch.dict(os.environ,{'RESEARCH_SANDBOX_IMAGE':'python:latest'}):
   with self.assertRaises(ValueError):execute({'execution_id':'research-'+'1'*32,'code':'print(1)','code_hash':hashlib.sha256(b'print(1)').hexdigest(),'seed':42,'datasets':[]})
 @unittest.skipUnless(shutil.which('docker') and os.getenv('RESEARCH_SANDBOX_IMAGE'),'Docker and an immutable experiment image required')
 def test_actual_isolated_python_execution_and_artifact(self):
  code="import socket,pathlib,os\nassert os.geteuid()==65534\ntry:\n socket.create_connection(('1.1.1.1',443),timeout=1)\n raise RuntimeError('network unexpectedly accessible')\nexcept OSError: pass\npathlib.Path('/work/output/result.txt').write_text('actual result')"
  r=execute({'execution_id':'research-'+'9'*32,'code':code,'code_hash':hashlib.sha256(code.encode()).hexdigest(),'seed':42,'datasets':[]});self.assertEqual(r['exit_code'],0);self.assertTrue(r['provenance']);self.assertEqual(len(r['artifacts']),1)
 @unittest.skipUnless(shutil.which('docker') and os.getenv('RESEARCH_PROCESSING_IMAGE'),'Docker and an immutable processing image required')
 def test_actual_isolated_document_processing(self):
  from .containers import processing
  p=fixture();p['format']='pdf';r=processing('export',p);text=processing('extract',{'data':r['data']});self.assertIn('Synthetic evidence',text['text'])

 @unittest.skipUnless(shutil.which('docker') and os.getenv('RESEARCH_SANDBOX_IMAGE'),'Docker and an immutable experiment image required')
 def test_actual_cancellation_terminates_container_and_fences_delayed_submission(self):
  import concurrent.futures,tempfile,time,subprocess
  identifier='research-'+'c'*32;code='import time\ntime.sleep(40)'
  payload={'execution_id':identifier,'code':code,'code_hash':hashlib.sha256(code.encode()).hexdigest(),'seed':42,'datasets':[]}
  with tempfile.TemporaryDirectory()as directory,patch.dict(os.environ,{'RESEARCH_CANCEL_DIR':directory}),concurrent.futures.ThreadPoolExecutor(max_workers=1)as pool:
   future=pool.submit(execute,payload);deadline=time.monotonic()+15
   while True:
    state=subprocess.run(['docker','inspect','--format','{{.State.Running}}',identifier],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL)
    if state.returncode==0 and state.stdout.strip()==b'true':break
    if future.done():future.result();self.fail('execution ended before cancellation')
    if time.monotonic()>deadline:self.fail('sandbox did not start')
    time.sleep(.1)
   self.assertEqual(cancel(identifier)['status'],'cancelled')
   with self.assertRaises(Exception):future.result(timeout=10)
   state=subprocess.run(['docker','inspect',identifier],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL);self.assertNotEqual(state.returncode,0)
   with self.assertRaisesRegex(ValueError,'execution_cancelled'):execute(payload)

 def test_reaper_only_removes_expired_managed_containers(self):
  from .containers import reap_expired,MANAGED_LABEL,EXPIRY_LABEL
  records=[{'Name':'/research-'+'a'*32,'Config':{'Labels':{MANAGED_LABEL:'true',EXPIRY_LABEL:'1'}}},{'Name':'/unrelated','Config':{'Labels':{MANAGED_LABEL:'true',EXPIRY_LABEL:'1'}}},{'Name':'/research-doc-'+'b'*32,'Config':{'Labels':{MANAGED_LABEL:'true',EXPIRY_LABEL:'9999999999'}}}]
  with patch('runtime.research.containers.command',side_effect=['a'*12+'\n'+'b'*12+'\n'+'c'*12,json.dumps([records[0]]),'',json.dumps([records[1]]),json.dumps([records[2]])])as command:
   reap_expired();removals=[call.args[0]for call in command.call_args_list if call.args[0][0]=='rm'];self.assertEqual(removals,[['rm','--force','a'*12]])

 def test_cross_references_and_docx_layout_survive_export(self):
  p=fixture();p['format']='md';p['document']['content'][1]['content'][2]['attrs']['id']='energy';p['document']['content'][2]['attrs']={'id':'results','caption':'Actual fixture table'}
  p['document']['content'].append({'type':'paragraph','content':[{'type':'crossReference','attrs':{'target':target}}for target in ['intro','energy','results']]})
  text=base64.b64decode(export(p)['data']).decode();self.assertIn('Section 1',text);self.assertIn('Equation 1',text);self.assertIn('Table 1',text)
  p['format']='docx';p['settings']['journal']='ieee';raw=base64.b64decode(export(p)['data']);d=Document(io.BytesIO(raw));self.assertEqual(d.styles['Normal'].font.size.pt,10)
  with zipfile.ZipFile(io.BytesIO(raw))as z:self.assertIn(b'w:num="2"',z.read('word/document.xml'))
 def test_unicode_pdf_and_docx_rtl_with_provisioned_fonts(self):
  from .unicode_fonts import SCRIPTS
  if not all(os.path.isfile(os.environ.get(env,default))for env,default,*_ in SCRIPTS.values()):self.skipTest('Noto fonts are required for Unicode PDF verification')
  p=fixture();p['document']['content']=[{'type':'paragraph','content':[{'type':'text','text':s}]}for s in ['বাংলা গবেষণা এবং বৈজ্ঞানিক প্রমাণ।','Русское научное исследование.','البحث العلمي يحتاج إلى أدلة موثوقة.','वैज्ञानिक अनुसंधान में विश्वसनीय साक्ष्य आवश्यक हैं।','科学研究需要可靠的证据和参考文献。']]
  p['format']='pdf';raw=base64.b64decode(export(p)['data']);self.assertTrue(PdfReader(io.BytesIO(raw)).pages)
  p['format']='docx';raw=base64.b64decode(export(p)['data'])
  with zipfile.ZipFile(io.BytesIO(raw))as z:self.assertIn(b'<w:bidi',z.read('word/document.xml'))
if __name__=='__main__':unittest.main()

