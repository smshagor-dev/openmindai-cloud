import hashlib,json,os,re,selectors,subprocess,time
from pathlib import Path

def image(name):
    value=os.environ.get(name,'')
    if not re.fullmatch(r'sha256:[a-f0-9]{64}',value):raise ValueError('immutable_image_id_required')
    return value

def command(args,timeout=10):
    return subprocess.run(['docker',*args],check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout).stdout.decode().strip()

def attached(name,payload,timeout,limit=26_000_000):
    p=subprocess.Popen(['docker','start','--attach','--interactive',name],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    data=json.dumps(payload).encode();offset=0;sel=selectors.DefaultSelector()
    for stream in [p.stdin,p.stdout,p.stderr]:os.set_blocking(stream.fileno(),False)
    sel.register(p.stdin,selectors.EVENT_WRITE);sel.register(p.stdout,selectors.EVENT_READ);sel.register(p.stderr,selectors.EVENT_READ);out=bytearray();err=bytearray();start=time.monotonic()
    try:
        while sel.get_map():
            if time.monotonic()-start>timeout:raise TimeoutError('container_timeout')
            for key,_ in sel.select(.2):
                if key.fileobj is p.stdin:
                    try:offset+=os.write(p.stdin.fileno(),data[offset:offset+65536])
                    except BrokenPipeError:offset=len(data)
                    if offset>=len(data):sel.unregister(p.stdin);p.stdin.close()
                    continue
                chunk=os.read(key.fileobj.fileno(),65536)
                if not chunk:sel.unregister(key.fileobj);continue
                (out if key.fileobj is p.stdout else err).extend(chunk)
                if len(out)>limit or len(err)>1_000_000:raise ValueError('container_output_limit')
        if p.wait(timeout=3)!=0:raise ValueError('container_failed: '+err.decode(errors='replace')[-1800:])
        return json.loads(out)
    finally:
        sel.close()
        if p.poll() is None:p.kill()
        p.wait(timeout=5)
        for stream in [p.stdin,p.stdout,p.stderr]:
            if stream and not stream.closed:stream.close()

def processing(operation,payload):
    import secrets
    name='research-doc-'+secrets.token_hex(16);im=image('RESEARCH_PROCESSING_IMAGE')
    args=['create','--name',name,'--network=none','--read-only','--user=65534:65534','--cap-drop=ALL','--security-opt=no-new-privileges','--memory=2g','--cpus=2','--pids-limit=96','--tmpfs=/tmp:rw,noexec,nosuid,size=384m,mode=1777','--interactive',im]
    command(args)
    try:return attached(name,{'operation':operation,'payload':payload},140)
    finally:command(['rm','--force',name])
