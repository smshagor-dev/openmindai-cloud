#!/usr/bin/env bash
# Run only inside a new trusted Vercel Sandbox build VM, before any user inputs.
set -euo pipefail
root=${1:?Usage: build-microvm.sh SOURCE_ROOT}
export DEBIAN_FRONTEND=noninteractive
sudo apt-get -o Acquire::Retries=2 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 update
sudo apt-get install -y --no-install-recommends pandoc lmodern texlive-fonts-recommended texlive-xetex texlive-lang-arabic fonts-noto-core fonts-noto-cjk fonts-dejavu-core
sudo mkdir -p /opt/research
sudo chown "$(id -u):$(id -g)" /opt/research
uv python install 3.12.15
uv venv --python 3.12.15 /opt/research/venv
uv pip install --python /opt/research/venv/bin/python pypdf==6.10.0 python-docx==1.2.0 citeproc-py==0.11.1 citeproc-py-styles==0.1.6 httpx2==2.16.0 fonttools==4.61.1 brotli==1.2.1 numpy==2.2.6 pandas==2.3.3 scipy==1.16.3 matplotlib==3.10.8 scikit-learn==1.7.2
sudo mkdir -p /opt/openmind/runtime/research /opt/openmind/frontend/public/research-csl
for file in cli.py documents.py exports.py unicode_fonts.py microvm.py; do
  sudo install -m 0644 "$root/runtime/research/$file" "/opt/openmind/runtime/research/$file"
done
sudo cp "$root"/frontend/public/research-csl/*.csl /opt/openmind/frontend/public/research-csl/
sudo /opt/research/venv/bin/python - <<'PY'
import hashlib, importlib.metadata, json, pathlib, subprocess
root=pathlib.Path('/opt/openmind')
files={str(p.relative_to(root)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(root.rglob('*')) if p.is_file() and p.name!='runtime-manifest.json'}
packages=sorted((d.metadata['Name'],d.version) for d in importlib.metadata.distributions())
data={'files':files,'packages':packages,'python':subprocess.check_output(['/opt/research/venv/bin/python','-V'],text=True).strip()}
digest='sha256:'+hashlib.sha256(json.dumps(data,sort_keys=True,separators=(',',':')).encode()).hexdigest()
(root/'runtime/research/runtime-manifest.json').write_text(json.dumps({'digest':digest,**data},sort_keys=True))
print(digest)
PY
