"""Fixed-code, stdin-only PDF extraction. No PDF actions, JavaScript or OCR."""
import base64
import io
import json
import re
import resource
import sys

resource.setrlimit(resource.RLIMIT_AS, (384 * 1024 * 1024,) * 2)
resource.setrlimit(resource.RLIMIT_CPU, (6, 7))
resource.setrlimit(resource.RLIMIT_FSIZE, (0, 0))
resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def excerpt(text, query, budget=4000):
    terms = {part.casefold() for part in query.split() if len(part) > 2}
    clauses = re.split(r'(?<=[.!?।])\s+|\n+', text)
    ranked = sorted(enumerate(clauses), key=lambda pair: (-sum(term in pair[1].casefold() for term in terms), pair[0]))
    chosen, used = [], 0
    for index, clause in ranked:
        if not clause.strip():
            continue
        if len(clause) > budget:
            position = next((clause.casefold().find(t) for t in terms if t in clause.casefold()), 0)
            clause = clause[max(0, position - 200):max(0, position - 200) + budget]
        if used + len(clause) + 1 > budget:
            continue
        chosen.append((index, clause))
        used += len(clause) + 1
    return '\n'.join(clause for _, clause in sorted(chosen))


def run():
    from pypdf import PdfReader, filters
    for name in ('ZLIB_MAX_OUTPUT_LENGTH', 'LZW_MAX_OUTPUT_LENGTH', 'RUN_LENGTH_MAX_OUTPUT_LENGTH', 'MAX_ARRAY_BASED_STREAM_OUTPUT_LENGTH', 'MAX_DECLARED_STREAM_LENGTH'):
        setattr(filters, name, 512_000)
    filters.ZLIB_MAX_RECOVERY_INPUT_LENGTH = 20_000
    raw = sys.stdin.buffer.read(3_000_001)
    if len(raw) > 3_000_000:
        raise ValueError('input size')
    packet = json.loads(raw)
    data = base64.b64decode(packet['bytes'], validate=True)
    if len(data) > 2_000_000 or not data.startswith(b'%PDF-'):
        raise ValueError('PDF bytes')
    reader = PdfReader(io.BytesIO(data), strict=True)
    if reader.is_encrypted:
        raise ValueError('encrypted')
    total = len(reader.pages)
    if total < 1 or total > 5000:
        raise ValueError('page tree')
    count = min(total, 8)
    numbers = [1] if count == 1 else [1 + i * (total - 1) // (count - 1) for i in range(count)]
    query = str(packet.get('query', ''))[:1000]
    pages = []
    for number in numbers:
        page = reader.pages[number - 1]
        content = page.get_contents()
        if content and len(content.get_data()) > 512_000:
            raise ValueError('page inflation')
        text = page.extract_text() or ''
        pages.append({'number': number, 'text': excerpt(text[:128_000], query)})
    if sum(len(page['text'].strip()) for page in pages) < 80:
        raise ValueError('insufficient text; OCR not provided')
    return {'pages': pages, 'pdf': {'total_pages': total, 'sampled_pages': numbers, 'partial': count < total, 'ocr': False}}


if __name__ == '__main__':
    try:
        sys.stdout.write(json.dumps(run(), ensure_ascii=False))
    except Exception:
        sys.stderr.write('Public PDF extraction unavailable: malformed, encrypted, oversized or unreadable document.\n')
        sys.exit(1)
