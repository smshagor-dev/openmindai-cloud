"""Real PHP -> signed HTTPS worker -> CPU geometry -> verified artifact smoke."""
import base64
import hashlib
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import trimesh


def main():
    repository = Path(__file__).resolve().parents[2]
    php = os.environ.get('STUDIO_TEST_PHP', 'php')
    php_ini = os.environ.get('STUDIO_TEST_PHP_INI')
    with tempfile.TemporaryDirectory(prefix='studio-bridge-') as directory:
        root = Path(directory)
        certificate = root / 'cert.pem'; key = root / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key), '-out', str(certificate), '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=IP:127.0.0.1,DNS:localhost', '-addext', 'basicConstraints=critical,CA:TRUE'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        with socket.socket() as sock: sock.bind(('127.0.0.1',0)); port=sock.getsockname()[1]
        env={**os.environ,'STUDIO_WORKER_KEY':'bridge-only-'+'a'*40,'STUDIO_WORKER_STATE':str(root/'state')}
        process=subprocess.Popen([os.sys.executable,'-m','uvicorn','runtime.studio.server:create_app','--factory','--host','127.0.0.1','--port',str(port),'--ssl-certfile',str(certificate),'--ssl-keyfile',str(key),'--log-level','error'],cwd=repository,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',port),timeout=.1): break
                except OSError: time.sleep(.05)
            else: raise RuntimeError('Worker did not bind')
            data=trimesh.creation.box().export(file_type='glb')
            source=root/'input.json';source.write_text(json.dumps({'data':base64.b64encode(data).decode(),'format':'glb','sha256':hashlib.sha256(data).hexdigest()}))
            script=root/'bridge.php';script.write_text('''<?php
require getcwd().'/vendor/autoload.php'; $app=require getcwd().'/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
config(['studio.worker_url'=>getenv('STUDIO_TEST_URL'),'studio.worker_key'=>getenv('STUDIO_WORKER_KEY')]);
$worker=app(App\\Services\\Studio\\Worker::class);$health=$worker->request('GET','/health');if(!($health['ready']??false))throw new RuntimeException('Worker not ready');
$id=(string)Illuminate\\Support\\Str::uuid();$input=json_decode(file_get_contents(getenv('STUDIO_TEST_INPUT')),true);
$request=['id'=>$id,'engine'=>'cpu','operation'=>'transform','input'=>$input,'params'=>['position'=>[3,0,0]]];
$worker->request('POST','/jobs',$request);$worker->request('POST','/jobs',$request);
for($i=0;$i<100;$i++){$status=$worker->request('GET','/jobs/'.$id);if($status['status']==='completed')break;if($status['status']==='failed')throw new RuntimeException('Worker failed');usleep(100000);}
$result=$worker->request('GET','/jobs/'.$id.'/artifact');
$request['id']=(string)Illuminate\\Support\\Str::uuid();$request['operation']='import';$request['params']=[];
$worker->request('POST','/jobs',$request);
for($i=0;$i<100;$i++){$status=$worker->request('GET','/jobs/'.$request['id']);if($status['status']==='completed')break;if($status['status']==='failed')throw new RuntimeException('Empty params import failed');usleep(100000);}
echo json_encode(['transform'=>$result,'import'=>$worker->request('GET','/jobs/'.$request['id'].'/artifact')]);
''')
            command=[php]+(['-c',php_ini] if php_ini else [])+['-d','curl.cainfo='+str(certificate),str(script)]
            call=subprocess.run(command,cwd=repository,env={**env,'APP_ENV':'testing','STUDIO_TEST_URL':f'https://127.0.0.1:{port}','STUDIO_TEST_INPUT':str(source)},capture_output=True,text=True,timeout=30,check=True)
            outputs=json.loads(call.stdout); result=outputs['transform']; output=base64.b64decode(result['data']); assert outputs['import']['format']=='glb'
            assert hashlib.sha256(output).hexdigest()==result['sha256']
            from runtime.studio.mesh import load
            mesh=load(output,'glb').to_mesh();assert len(mesh.faces)==12 and abs(mesh.bounds[0][0]-2.5)<.0001
            print('Real signed PHP/HTTPS/CPU worker bridge: PASS (12 faces, persisted GLB, translated bounds, idempotent job)')
        finally:
            process.terminate()
            try: process.wait(timeout=5)
            except subprocess.TimeoutExpired: process.kill();process.wait()

if __name__=='__main__':main()
