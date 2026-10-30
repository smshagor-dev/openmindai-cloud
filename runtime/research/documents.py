import base64,io
from pypdf import PdfReader

def extract(payload):
    raw=base64.b64decode(payload['data'],validate=True)
    if not raw.startswith(b'%PDF-') or len(raw)>8_000_000: raise ValueError('invalid_pdf')
    reader=PdfReader(io.BytesIO(raw),strict=True)
    if reader.is_encrypted or len(reader.pages)>400: raise ValueError('pdf_not_supported')
    text=[];size=0
    for page in reader.pages:
        part=page.extract_text() or '';size+=len(part.encode())
        if size>2_000_000: raise ValueError('pdf_text_limit')
        text.append(part)
    return {'text':'\n\n'.join(text),'pages':len(reader.pages),'ocr':False}
