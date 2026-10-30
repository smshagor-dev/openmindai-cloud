# Test-only adapter: real native converters with mocked container transport.
# Production server has no native execution switch.
from runtime.research import server
from runtime.research.documents import extract
from runtime.research.exports import export
server.processing=lambda operation,payload:extract(payload) if operation=='extract' else export(payload)
app=server.app
