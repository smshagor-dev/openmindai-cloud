#!/bin/sh
# Private user-space installation; no root access, GPU or public Python listener.
set -eu
umask 077
runtime_dir=${1:?Usage: install.sh PRIVATE_RUNTIME_DIRECTORY PYTHON_3_11_OR_NEWER}
python_bin=${2:?A Python 3.11-3.13 executable is required}
"$python_bin" -c 'import sys; assert (3,11) <= sys.version_info[:2] < (3,14)'
upstream=https://github.com/NVIDIA-AI-Blueprints/deep-researcher-agent.git
revision=a6d9efdec69a3052e1ee7f15a45d25711ac05d2a
if [ ! -d "$runtime_dir/.git" ]; then
  [ ! -e "$runtime_dir" ] || { echo 'Destination exists and is not the Blueprint repository.' >&2; exit 1; }
  git clone --depth 1 "$upstream" "$runtime_dir"
fi
[ "$(git -C "$runtime_dir" remote get-url origin)" = "$upstream" ] || { echo 'Unexpected upstream repository.' >&2; exit 1; }
git -C "$runtime_dir" diff --quiet HEAD || { echo 'Preserve local upstream changes before installing.' >&2; exit 1; }
git -C "$runtime_dir" fetch --depth 1 origin "$revision"
git -C "$runtime_dir" checkout --detach "$revision"
tools_dir="$runtime_dir-tools"
[ -d "$tools_dir" ] || "$python_bin" -m venv "$tools_dir"
"$tools_dir/bin/python" -m pip install uv
cd "$runtime_dir"
UV_CONCURRENT_BUILDS=1 UV_CONCURRENT_DOWNLOADS=2 UV_CONCURRENT_INSTALLS=1 RAYON_NUM_THREADS=1 TOKIO_WORKER_THREADS=1 OPENBLAS_NUM_THREADS=1 OMP_NUM_THREADS=1 MAKEFLAGS=-j1 CC=${CC:-gcc} CXX=${CXX:-g++} "$tools_dir/bin/uv" sync --frozen --no-dev --no-cache --python "$python_bin"
echo 'Pinned NVIDIA runtime installed. Configure RESEARCH_BLUEPRINT_ROOT and RESEARCH_BLUEPRINT_PYTHON in the private Laravel environment.'
