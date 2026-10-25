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
  code="import socket,pathlib\ntry:\n socket.create_connection(('1.1.1.1',443),timeout=1)\n raise RuntimeError('network unexpectedly accessible')\nexcept OSError: pass\npathlib.Path('/work/output/result.txt').write_text('actual result')"
  r=execute({'execution_id':'research-'+'9'*32,'code':code,'code_hash':hashlib.sha256(code.encode()).hexdigest(),'seed':42,'datasets':[]});self.assertEqual(r['exit_code'],0);self.assertTrue(r['provenance']);self.assertEqual(len(r['artifacts']),1)
 @unittest.skipUnless(shutil.which('docker') and os.getenv('RESEARCH_PROCESSING_IMAGE'),'Docker and an immutable processing image required')
 def test_actual_isolated_document_processing(self):
  from .containers import processing
  p=fixture();p['format']='pdf';r=processing('export',p);text=processing('extract',{'data':r['data']});self.assertIn('Synthetic evidence',text['text'])
if __name__=='__main__':unittest.main()
