"""Bounded, independently implemented mesh operations. No external resolvers."""
from __future__ import annotations
import base64
import io
import json
import math
import struct
import warnings
from PIL import Image
Image.MAX_IMAGE_PIXELS = 16_777_216
warnings.filterwarnings('error', category=Image.DecompressionBombWarning)
import numpy as np
import trimesh

FORMATS = {'glb', 'gltf', 'obj', 'stl', 'ply', 'json'}
MAX_BYTES = 3_000_000
MAX_VERTICES = 200_000
MAX_FACES = 400_000


def bounded_document(document):
    if len(document.get('meshes', [])) > 100 or len(document.get('nodes', [])) > 1000:
        raise ValueError('Scene complexity limit exceeded')
    for item in document.get('buffers', []) + document.get('bufferViews', []):
        if type(item.get('byteLength')) is not int or not 0 <= item['byteLength'] <= MAX_BYTES:
            raise ValueError('Unsafe buffer length')
    visiting = set(); visited = set(); nodes = document.get('nodes', [])
    def visit(index):
        if type(index) is not int or not 0 <= index < len(nodes) or index in visiting: raise ValueError('Invalid scene graph')
        if index in visited: return
        visiting.add(index)
        for child in nodes[index].get('children', []): visit(child)
        visiting.remove(index); visited.add(index)
    for index in range(len(nodes)): visit(index)
    if document.get('extensionsRequired'):
        raise ValueError('Compressed/required glTF extensions are not supported')
    for item in document.get('accessors', []):
        if not isinstance(item.get('count'), int) or not 0 <= item['count'] <= MAX_FACES * 3:
            raise ValueError('Unsafe glTF accessor size')
    for item in document.get('buffers', []) + document.get('images', []):
        uri = item.get('uri')
        if uri is not None:
            if not isinstance(uri, str) or not uri.startswith('data:') or ';base64,' not in uri:
                raise ValueError('External glTF resources are not allowed')
            raw = base64.b64decode(uri.split(';base64,', 1)[1], validate=True)
            if len(raw) > MAX_BYTES:
                raise ValueError('Embedded resource is too large')


def load(data: bytes, fmt: str):
    if fmt not in FORMATS or not 0 < len(data) <= MAX_BYTES:
        raise ValueError('Unsupported or oversized mesh')
    if fmt == 'json':
        document = json.loads(data)
        if isinstance(document, dict) and set(document) == {'title', 'description', 'nodes', 'edges'}:
            return procedural(document)
        if set(document) != {'schema', 'glb'} or document['schema'] != 'openmind.scene/1':
            raise ValueError('Invalid self-contained scene manifest')
        return load(base64.b64decode(document['glb'], validate=True), 'glb')
    if fmt == 'glb':
        if len(data) < 20 or struct.unpack('<4sII', data[:12]) != (b'glTF', 2, len(data)):
            raise ValueError('Invalid GLB header')
        size, kind = struct.unpack('<II', data[12:20])
        if kind != 0x4E4F534A or size > len(data) - 20:
            raise ValueError('Invalid GLB JSON chunk')
        bounded_document(json.loads(data[20:20 + size]))
    elif fmt == 'gltf':
        bounded_document(json.loads(data))
    elif fmt == 'obj':
        for line in data.decode('utf-8', errors='strict').splitlines():
            parts = line.split()
            if parts and (parts[0].lower() in {'mtllib', 'call', 'csh'} or len(parts) > 64):
                raise ValueError('External OBJ resources or oversized faces are not allowed')
    elif fmt == 'stl' and len(data) >= 84:
        count = struct.unpack('<I', data[80:84])[0]
        if not data.lstrip().startswith(b'solid') and (count > MAX_FACES or 84 + count * 50 != len(data)):
            raise ValueError('Invalid binary STL')
    elif fmt == 'ply' and b'texturefile' in data[:10000].lower():
        raise ValueError('External PLY textures are not allowed')
    scene = trimesh.load_scene(io.BytesIO(data), file_type=fmt, resolver={}, process=False)
    if not scene.geometry or len(scene.geometry) > 100:
        raise ValueError('Mesh has no supported geometry')
    vertices = faces = 0
    for g in scene.geometry.values():
        if not isinstance(g, trimesh.Trimesh):
            raise ValueError('Only triangle meshes are supported')
        vertices += len(g.vertices)
        faces += len(g.faces)
        if not np.isfinite(g.vertices).all() or np.abs(g.vertices).max(initial=0) > 100000:
            raise ValueError('Unsafe geometry coordinates')
        if len(g.faces) == 0 or g.faces.min() < 0 or g.faces.max() >= len(g.vertices):
            raise ValueError('Invalid triangle indices')
    if vertices > MAX_VERTICES or faces > MAX_FACES:
        raise ValueError('Geometry complexity limit exceeded')
    for node in scene.graph.nodes_geometry:
        matrix, _ = scene.graph[node]
        if not np.isfinite(matrix).all() or np.abs(matrix).max() > 100000:
            raise ValueError('Unsafe scene transform')
    return scene


def export(scene, fmt):
    if fmt not in FORMATS:
        raise ValueError('Unsupported export format')
    geometry = scene if fmt in {'glb', 'gltf', 'json'} else scene.to_mesh()
    if fmt == 'json':
        result = json.dumps({'schema': 'openmind.scene/1', 'glb': base64.b64encode(scene.export(file_type='glb')).decode()}, separators=(',', ':')).encode()
    else:
        result = geometry.export(file_type=fmt)
    if fmt == 'gltf':
        main = next(name for name in result if name.endswith('.gltf'))
        document = json.loads(result[main])
        for item in document.get('buffers', []) + document.get('images', []):
            name = item.get('uri')
            if name:
                content = result[name]
                media = 'image/png' if name.endswith('.png') else 'application/octet-stream'
                item['uri'] = 'data:' + media + ';base64,' + base64.b64encode(content).decode()
        result = json.dumps(document, separators=(',', ':')).encode()
    if isinstance(result, str):
        result = result.encode()
    if not 0 < len(result) <= MAX_BYTES:
        raise ValueError('Export exceeds worker artifact size limit')
    # Every output is reopened, including transforms and embedded resources.
    reopened = load(result, fmt)
    return result, inspect(reopened)


def inspect(scene):
    mesh = scene.to_mesh()
    return {'vertices': int(len(mesh.vertices)), 'faces': int(len(mesh.faces)),
            'objects': len(scene.graph.nodes_geometry), 'bounds': mesh.bounds.tolist(),
            'watertight': bool(mesh.is_watertight), 'volume': float(mesh.volume) if math.isfinite(mesh.volume) else None}


def execute(data: bytes, fmt: str, operation: str, params: dict):
    scene = load(data, fmt)
    allowed = {'import': set(), 'inspect': set(), 'export': {'format'}, 'repair': set(),
               'smooth': {'iterations', 'strength'}, 'decimate': {'ratio'},
               'transform': {'position', 'rotation', 'scale'}}
    if operation not in allowed or set(params) - allowed[operation]:
        raise ValueError('Unsupported mesh operation or parameters')
    if operation in {'smooth', 'decimate', 'repair'}:
        mesh = scene.to_mesh()
        if operation == 'smooth':
            iterations = params.get('iterations', 5)
            strength = params.get('strength', 0.5)
            if type(iterations) is not int or not 1 <= iterations <= 50 or not isinstance(strength, (int, float)) or not 0.1 <= strength <= 0.9:
                raise ValueError('Invalid smoothing settings')
            trimesh.smoothing.filter_taubin(mesh, lamb=strength, nu=min(0.99, strength + 0.05), iterations=iterations)
        elif operation == 'decimate':
            ratio = params.get('ratio', 0.5)
            if type(ratio) not in (int, float) or not 0.05 <= ratio <= 0.95:
                raise ValueError('Invalid decimation ratio')
            mesh = mesh.simplify_quadric_decimation(face_count=max(4, int(len(mesh.faces) * ratio)))
        else:
            mesh.update_faces(mesh.nondegenerate_faces())
            mesh.update_faces(mesh.unique_faces())
            mesh.remove_unreferenced_vertices()
            trimesh.repair.fix_normals(mesh, multibody=True)
            trimesh.repair.fill_holes(mesh)
        scene = trimesh.Scene(mesh)
    elif operation == 'transform':
        vectors = {}
        for key, default, limit in [('position', [0, 0, 0], 10000), ('rotation', [0, 0, 0], 6.284), ('scale', [1, 1, 1], 100)]:
            value = params.get(key, default)
            if not isinstance(value, list) or len(value) != 3 or any(type(v) not in (int, float) or not math.isfinite(v) or abs(v) > limit or (key == 'scale' and v < 0.001) for v in value):
                raise ValueError('Invalid transform')
            vectors[key] = value
        transform = trimesh.transformations.compose_matrix(translate=vectors['position'], angles=vectors['rotation'], scale=vectors['scale'])
        scene.apply_transform(transform)
    result, metadata = export(scene, params.get('format', 'glb') if operation == 'export' else 'glb')
    metadata['format_limitations'] = 'OBJ/STL/PLY flatten hierarchy; STL has no material or texture data. Processing may merge objects and discard texture UVs.'
    return result, metadata


def compose(data):
    items = json.loads(data)
    if not isinstance(items, list) or not 1 <= len(items) <= 10: raise ValueError('Invalid scene composition')
    scene = trimesh.Scene()
    for index, item in enumerate(items):
        if set(item) != {'data', 'format'}: raise ValueError('Invalid composition object')
        child = load(base64.b64decode(item['data'], validate=True), item['format'])
        for node in child.graph.nodes_geometry:
            transform, geometry = child.graph[node]
            scene.add_geometry(child.geometry[geometry].copy(), node_name=f'object-{index}:{node}', transform=transform)
    result, metadata = export(scene, 'glb')
    metadata['kind'] = 'scene'
    return result, metadata


def procedural(document):
    import re
    nodes = document['nodes']; edges = document['edges']
    if not isinstance(nodes, list) or not 1 <= len(nodes) <= 80 or not isinstance(edges, list) or len(edges) > 120:
        raise ValueError('Invalid procedural scene')
    scene = trimesh.Scene(); positions = {}; hidden = set()
    for node in nodes:
        if not isinstance(node, dict) or set(node) - {'id','label','shape','position','rotation','scale','color','hidden'}:
            raise ValueError('Invalid scene node')
        identity = node.get('id'); label = node.get('label')
        if not isinstance(identity, str) or not re.fullmatch('[a-zA-Z0-9_-]{1,60}', identity) or identity in positions or not isinstance(label, str) or len(label) > 100:
            raise ValueError('Invalid scene identity')
        color = node.get('color', '')
        if not isinstance(color, str) or not re.fullmatch('#[a-fA-F0-9]{6}', color): raise ValueError('Invalid material color')
        for key, low, high in [('position',-40,40),('rotation',-6.284,6.284),('scale',.05,20)]:
            value = node.get(key)
            if not isinstance(value, list) or len(value) != 3 or any(type(v) not in (int,float) or not math.isfinite(v) or not low <= v <= high for v in value):
                raise ValueError('Invalid scene transform')
        positions[identity] = node['position']
        if node.get('hidden', False): hidden.add(identity); continue
        shape = node.get('shape')
        if shape == 'box': geometry = trimesh.creation.box()
        elif shape == 'sphere': geometry = trimesh.creation.icosphere(subdivisions=2,radius=.5)
        elif shape == 'cylinder':
            geometry = trimesh.creation.cylinder(radius=.5,height=1,sections=24)
            geometry.apply_transform(trimesh.transformations.rotation_matrix(-math.pi/2,[1,0,0]))
        elif shape == 'cone':
            geometry = trimesh.creation.cone(radius=.5,height=1,sections=24);geometry.apply_translation([0,0,-.5]);geometry.apply_transform(trimesh.transformations.rotation_matrix(-math.pi/2,[1,0,0]))
        elif shape == 'torus': geometry = trimesh.creation.torus(major_radius=.5,minor_radius=.15,major_sections=32,minor_sections=12)
        else: raise ValueError('Unsupported procedural shape')
        geometry.visual.face_colors = [int(color[i:i+2],16) for i in (1,3,5)] + [255]
        # Match Three.js XYZ Euler convention (intrinsic rotations).
        # Three scales in local coordinates, then rotates and translates.
        transform = trimesh.transformations.translation_matrix(node['position']) @ trimesh.transformations.euler_matrix(*node['rotation'],axes='rxyz') @ np.diag(node['scale']+[1])
        scene.add_geometry(geometry, node_name=identity, geom_name=identity, transform=transform)
    for index,edge in enumerate(edges):
        if not isinstance(edge,dict) or set(edge)!={'from','to','label'} or edge['from'] not in positions or edge['to'] not in positions or edge['from']==edge['to']:
            raise ValueError('Invalid architecture edge')
        if edge['from'] in hidden or edge['to'] in hidden: continue
        start=np.array(positions[edge['from']]);end=np.array(positions[edge['to']]);length=np.linalg.norm(end-start)
        if length<.001: raise ValueError('Overlapping connection endpoints')
        geometry=trimesh.creation.cylinder(radius=.025,height=length,sections=8)
        transform=trimesh.geometry.align_vectors([0,0,1],(end-start)/length);transform[:3,3]=(start+end)/2
        scene.add_geometry(geometry,node_name=f'connection-{index}',transform=transform)
    if not scene.geometry: raise ValueError('No visible scene geometry')
    return scene
