import json,sys
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parents[2]))
from runtime.research.documents import extract
from runtime.research.exports import export
request=json.load(sys.stdin)
if request['operation'] not in ['extract','export']:raise ValueError('unsupported_operation')
print(json.dumps((extract if request['operation']=='extract' else export)(request['payload']),ensure_ascii=False))
