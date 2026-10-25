import hmac,json,os,threading
from fastapi import FastAPI,Request,HTTPException
from fastapi.responses import JSONResponse
from .containers import processing
from .sandbox import execute,cancel
app=FastAPI(docs_url=None,redoc_url=None,openapi_url=None)
capacity=threading.BoundedSemaphore(3)
@app.middleware('http')
async def protect(request:Request,call_next):
    secret=os.environ.get('RESEARCH_WORKER_KEY','')
    if len(secret)<32 or not hmac.compare_digest(request.headers.get('authorization',''),'Bearer '+secret):return JSONResponse({'error_code':'unauthorized'},status_code=401)
    if request.method=='POST':
        body=bytearray()
        async for part in request.stream():
            body.extend(part)
            if len(body)>24_000_000:return JSONResponse({'error_code':'body_limit'},status_code=413)
        request._body=bytes(body)
    return await call_next(request)
@app.get('/health')
def health():return {'status':'ready','document_processing':'isolated_container','experiments':'isolated_container'}
@app.post('/{operation}')
def run(operation:str,payload:dict):
    if operation not in ['extract','export','experiment','cancel']:raise HTTPException(404)
    if not capacity.acquire(blocking=False):raise HTTPException(429,'Worker capacity reached.')
    try:
        if operation in ['extract','export']:return processing(operation,payload)
        return execute(payload) if operation=='experiment' else cancel(payload.get('execution_id'))
    except (ValueError,KeyError,TimeoutError):raise HTTPException(422,'Research processing failed validation or execution.')
    except Exception:raise HTTPException(503,'Research worker unavailable.')
    finally:capacity.release()
