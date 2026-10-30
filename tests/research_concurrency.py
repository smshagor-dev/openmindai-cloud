"""Two actual PHP processes overlap while exercising project locks and source deduplication."""
import json,os,subprocess,tempfile,time,shutil
from pathlib import Path
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='openmind-research-concurrency-')as directory:
 d=Path(directory);db=d/'database.sqlite';db.touch();env={**os.environ,'APP_ENV':'testing','APP_KEY':'base64:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa=','DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'QUEUE_CONNECTION':'database','CACHE_STORE':'array','SESSION_DRIVER':'array'}
 subprocess.run(['php','artisan','migrate','--force'],cwd=root,env=env,check=True,stdout=subprocess.DEVNULL)
 fixture=['php','tests/fixtures/research-concurrent.php']
 data=json.loads(subprocess.check_output([*fixture,'init'],cwd=root,env=env))
 processes=[subprocess.Popen([*fixture,'worker',str(i),directory],cwd=root,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE)for i in data['tasks']]
 start=time.monotonic()
 while not all((d/('ready-'+str(i))).exists()for i in data['tasks']):
  if time.monotonic()-start>15:raise RuntimeError('PHP workers did not reach the barrier')
  time.sleep(.01)
 (d/'go').touch();results=[]
 for p in processes:
  out,err=p.communicate(timeout=20)
  assert p.returncode==0,err.decode();results.append(json.loads(out))
 assert len({r['source_id']for r in results})==1,results
 assert max(r['started']for r in results)<min(r['finished']for r in results),results
 print('Two PHP task processes overlapped; both completed; one source was deduplicated.')

 if shutil.which('flock') and shutil.which('timeout'):
  queued=json.loads(subprocess.check_output([*fixture,'queue'],cwd=root,env=env));assert queued['queued']==1,queued
  runner=subprocess.run(['sh',str(root/'runtime/research/cpanel-queue.sh'),str(root),shutil.which('php'),'1'],cwd=root,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20)
  assert runner.returncode==0,runner.stderr.decode()
  state=json.loads(subprocess.check_output([*fixture,'check-queue'],cwd=root,env=env));assert state=={'queued':0,'failed':0,'preserved':True},state
  print('Actual scheduled CLI consumer acknowledged one research job and preserved the newer source.')
 else:print('SKIP actual scheduled consumer: GNU flock/timeout unavailable.')
