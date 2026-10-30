import base64
import json
from pathlib import Path
import subprocess
import sys
import unittest
import zlib


def fixture(count=24, bomb=False):
    objects = [b'<< /Type /Catalog /Pages 2 0 R >>', ('<< /Type /Pages /Kids [' + ' '.join(f'{4+i*2} 0 R' for i in range(count)) + f'] /Count {count} >>').encode(), b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>']
    for index in range(count):
        text = f'BT /F1 10 Tf 40 700 Td (Readable research evidence on page {index+1}.) Tj 0 -20 Td (GPS navigation reached {"97.5" if index==count-1 else "50"} percent accuracy in actual scientific measurements with the baseline.) Tj ET'.encode()
        if bomb:
            text = zlib.compress(b' ' * 600_000 + text)
        objects += [(f'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents {5+index*2} 0 R >>').encode(), (f'<< /Length {len(text)} '+('/Filter /FlateDecode ' if bomb else '')+'>>\nstream\n').encode()+text+b'\nendstream']
    pdf, offsets = b'%PDF-1.4\n', [0]
    for index, obj in enumerate(objects):
        offsets.append(len(pdf))
        pdf += f'{index+1} 0 obj\n'.encode()+obj+b'\nendobj\n'
    start = len(pdf)
    pdf += f'xref\n0 {len(objects)+1}\n0000000000 65535 f \n'.encode()+b''.join(f'{offset:010d} 00000 n \n'.encode() for offset in offsets[1:])+f'trailer\n<< /Size {len(objects)+1} /Root 1 0 R >>\nstartxref\n{start}\n%%EOF'.encode()
    return pdf


def parse(data):
    return subprocess.run([sys.executable, '-I', '-B', str(Path(__file__).with_name('pdf.py'))], input=json.dumps({'bytes': base64.b64encode(data).decode(), 'query': 'GPS navigation accuracy measurements'}).encode(), capture_output=True, timeout=12)


class PdfTest(unittest.TestCase):
    def test_real_pdf_later_page_provenance(self):
        result = parse(fixture())
        self.assertEqual(result.returncode, 0, result.stderr)
        doc = json.loads(result.stdout)
        self.assertEqual(doc['pdf']['total_pages'], 24)
        self.assertTrue(doc['pdf']['partial'])
        self.assertEqual(len(doc['pages']), 8)
        self.assertEqual(doc['pages'][-1]['number'], 24)
        self.assertIn('97.5 percent', doc['pages'][-1]['text'])

    def test_malformed_and_inflation_bomb_fail_closed(self):
        for data in [b'%PDF-malformed', fixture(1, bomb=True), b'x'*2_000_001]:
            result = parse(data)
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn(b'Readable research evidence', result.stderr)


if __name__ == '__main__':
    unittest.main()
