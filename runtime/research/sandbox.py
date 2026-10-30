import base64,hashlib,json,os,re,subprocess,time
from pathlib import Path
from .containers import image,command,attached,expiry_labels

BOOTSTRAP=r"""
import base64,hashlib,importlib.metadata,json,os,pathlib,random,subprocess,sys,tempfile,time,signal
p=json.load(sys.stdin);root=pathlib.Path('/work');(root/'input').mkdir();(root/'output').mkdir();os.chmod(root/'output',0o777)
inputs=[]
for d in p['datasets']:
 data=base64.b64decode(d['data'],validate=True);name=d['name']
 if '/'in name or name.startswith('.') or hashlib.sha256(data).hexdigest()!=d['sha256']:raise ValueError('dataset_manifest_invalid')
 (root/'input'/name).write_bytes(data);inputs.append({'name':name,'sha256':d['sha256'],'bytes':len(data)})
code=p['code'];(root/'experiment.py').write_text(code)
wrapper='import random,runpy\nrandom.seed('+str(p['seed'])+')\ntry:\n import numpy as np\n np.random.seed('+str(p['seed'])+')\nexcept ImportError: pass\nrunpy.run_path("/work/experiment.py",run_name="__main__")\n';(root/'runner.py').write_text(wrapper)
start=time.monotonic()
with tempfile.TemporaryFile() as out,tempfile.TemporaryFile()as err:
 def drop_privileges():
  os.setgroups([]);os.setgid(65534);os.setuid(65534)
 child=subprocess.Popen([sys.executable,'-I',str(root/'runner.py')],cwd=root,stdout=out,stderr=err,preexec_fn=drop_privileges,start_new_session=True,env={'PATH':os.environ.get('PATH',''),'HOME':'/tmp','MPLCONFIGDIR':'/tmp/matplotlib','PYTHONHASHSEED':str(p['seed']),'OMP_NUM_THREADS':'1','OPENBLAS_NUM_THREADS':'1'})
 while child.poll()is None:
  if time.monotonic()-start>50 or out.tell()+err.tell()>1_000_000:child.kill();child.wait();break
  time.sleep(.1)
 try:os.killpg(child.pid,signal.SIGKILL)
 except ProcessLookupError:pass
 out.seek(0);err.seek(0);stdout=out.read(500000).decode(errors='replace');stderr=err.read(500000).decode(errors='replace')
artifacts=[];total=0
for f in (root/'output').rglob('*'):
 if f.is_symlink():continue
 if f.is_file():
  if not f.resolve().is_relative_to((root/'output').resolve()):continue
  size=f.stat().st_size
  if len(artifacts)>=20 or size>8_000_000 or total+size>12_000_000:raise ValueError('artifact_limit')
  b=f.read_bytes();total+=size;artifacts.append({'name':str(f.relative_to(root/'output')),'data':base64.b64encode(b).decode(),'sha256':hashlib.sha256(b).hexdigest()})
print(json.dumps({'exit_code':child.returncode,'stdout':stdout,'stderr':stderr,'artifacts':artifacts,'dependencies':{n:importlib.metadata.version(n) for n in ['numpy','pandas','scipy','matplotlib','scikit-learn']},'inputs':inputs,'elapsed_seconds':time.monotonic()-start}))
"""

def cancel(identifier):
    if not re.fullmatch(r'research-[a-f0-9]{32}',identifier or ''):raise ValueError('invalid_execution_id')
    directory=Path(os.environ.get('RESEARCH_CANCEL_DIR','/tmp/openmind-research-cancellations'));directory.mkdir(mode=0o700,parents=True,exist_ok=True);(directory/identifier).touch()
    p=subprocess.run(['docker','rm','--force',identifier],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10)
    # No such container also means it cannot still be executing. Tombstone fences a later start.
    if p.returncode and b'No such container' not in p.stderr:raise ValueError('cancellation_unconfirmed')
    return {'status':'cancelled','execution_id':identifier}

def execute(payload):
    identifier=payload.get('execution_id','');im=image('RESEARCH_SANDBOX_IMAGE')
    if not re.fullmatch(r'research-[a-f0-9]{32}',identifier):raise ValueError('invalid_execution_id')
    code=payload['code']
    if len(code)>30000 or hashlib.sha256(code.encode()).hexdigest()!=payload['code_hash']:raise ValueError('code_checksum')
    if len(payload.get('datasets',[]))>10 or sum(len(d['data']) for d in payload['datasets'])>12_000_000:raise ValueError('input_limit')
    tombstone=Path(os.environ.get('RESEARCH_CANCEL_DIR','/tmp/openmind-research-cancellations'))/identifier
    if tombstone.exists():raise ValueError('execution_cancelled')
    tombstone.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    guard=tombstone.parent/(identifier+'.submitted')
    try:
        with guard.open('x')as file:file.write(payload['code_hash'])
    except FileExistsError:raise ValueError('execution_already_submitted')
    command(['create','--name',identifier,'--network=none','--read-only','--user=0:0','--cap-drop=ALL','--cap-add=SETUID','--cap-add=SETGID','--cap-add=KILL','--security-opt=no-new-privileges','--memory=512m','--cpus=1','--pids-limit=64','--tmpfs=/work:rw,nosuid,size=128m,mode=1777','--tmpfs=/tmp:rw,noexec,nosuid,size=32m,mode=1777','--interactive',*expiry_labels(90),im,'python','-I','-c',BOOTSTRAP])
    try:
        if tombstone.exists():raise ValueError('execution_cancelled')
        result=attached(identifier,payload,65,20_000_000)
        result['provenance']={'execution_id':identifier,'image':im,'code_hash':payload['code_hash'],'seed':payload['seed'],'bootstrap_hash':hashlib.sha256(BOOTSTRAP.encode()).hexdigest(),'executed_at':time.time(),'network':'none','inputs':result.pop('inputs')}
        return result
    finally:command(['rm','--force',identifier])
