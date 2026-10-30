import * as THREE from "three";
import type { SceneNode, StudioScene } from "./studio-scene.ts";

// Game-engine export for procedural scenes: low-poly flat-shaded parts, one
// named object per component with its pivot at the part centre, Y-up, metres.

export type AssetPart = { name: string; node: SceneNode; geometry: THREE.BufferGeometry };

export function lowPolyGeometry(shape: SceneNode["shape"]): THREE.BufferGeometry {
  const source =
    shape === "sphere" ? new THREE.SphereGeometry(0.5, 12, 8)
      : shape === "cylinder" ? new THREE.CylinderGeometry(0.5, 0.5, 1, 12)
        : shape === "cone" ? new THREE.ConeGeometry(0.5, 1, 12)
          : shape === "torus" ? new THREE.TorusGeometry(0.5, 0.15, 8, 16)
            : new THREE.BoxGeometry(1, 1, 1);
  // Non-indexed vertices give every face its own normal: flat, faceted shading.
  const flat = source.index ? source.toNonIndexed() : source;
  if (flat !== source) source.dispose();
  flat.computeVertexNormals();
  // Drop zero-area triangles (sphere poles, cone apex) so counts are honest.
  const p = flat.getAttribute("position"), keep: number[] = [];
  const a = new THREE.Vector3(), b = new THREE.Vector3(), c = new THREE.Vector3();
  for (let t = 0; t < p.count; t += 3) {
    a.fromBufferAttribute(p, t); b.fromBufferAttribute(p, t + 1); c.fromBufferAttribute(p, t + 2);
    if (b.clone().sub(a).cross(c.clone().sub(a)).lengthSq() > 1e-12) keep.push(t, t + 1, t + 2);
  }
  if (keep.length === p.count) return flat;
  const compact = new THREE.BufferGeometry();
  for (const name of ["position", "normal", "uv"]) {
    const attr = flat.getAttribute(name);
    if (!attr) continue;
    const size = attr.itemSize, data = new Float32Array(keep.length * size);
    keep.forEach((v, i) => { for (let k = 0; k < size; k++) data[i * size + k] = attr.array[v * size + k]; });
    compact.setAttribute(name, new THREE.BufferAttribute(data, size));
  }
  flat.dispose();
  return compact;
}

export function assetParts(scene: StudioScene): AssetPart[] {
  const used = new Set<string>();
  return scene.nodes.filter((node) => !node.hidden).map((node) => {
    const base = (node.label || node.id).replace(/[^A-Za-z0-9_]+/g, "_").replace(/^_+|_+$/g, "").slice(0, 48) || "Part";
    let name = base;
    for (let n = 2; used.has(name); n++) name = `${base}_${n}`;
    used.add(name);
    return { name, node, geometry: lowPolyGeometry(node.shape) };
  });
}

export const triangleCount = (parts: AssetPart[]) =>
  parts.reduce((sum, part) => sum + part.geometry.getAttribute("position").count / 3, 0);

export function lowPolyGroup(scene: StudioScene): THREE.Group {
  const group = new THREE.Group();
  group.name = scene.title || "OpenMindAI_Asset";
  for (const part of assetParts(scene)) {
    const mesh = new THREE.Mesh(part.geometry, new THREE.MeshStandardMaterial({
      name: `${part.name}_Mat`, color: part.node.color, roughness: 0.6, metalness: 0.1, flatShading: true,
    }));
    mesh.name = part.name;
    mesh.position.fromArray(part.node.position);
    mesh.rotation.set(...part.node.rotation);
    mesh.scale.fromArray(part.node.scale);
    group.add(mesh);
  }
  return group;
}

const num = (v: number) => (Math.abs(v) < 1e-9 ? 0 : Number(v.toFixed(6)));
const list = (values: number[]) => values.map(num).join(",");
const srgb = (hex: string) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);

/** ASCII FBX 7.4 (Unity/Blender importable). Y-up, Z-forward, 1 unit = 1 m. */
export function sceneToFbx(scene: StudioScene): string {
  const parts = assetParts(scene);
  let objects = "", connections = "";
  parts.forEach((part, i) => {
    const geometryId = 1000000 + i * 3, modelId = geometryId + 1, materialId = geometryId + 2;
    const pos = part.geometry.getAttribute("position"), normal = part.geometry.getAttribute("normal"), uv = part.geometry.getAttribute("uv");
    const indices: number[] = [];
    for (let v = 0; v < pos.count; v += 3) indices.push(v, v + 1, -(v + 2) - 1);
    // three.js XYZ applies Z first; FBX eEulerXYZ applies X first. Re-express in ZYX.
    const euler = new THREE.Euler().setFromQuaternion(
      new THREE.Quaternion().setFromEuler(new THREE.Euler(...part.node.rotation, "XYZ")), "ZYX");
    const deg = [euler.x, euler.y, euler.z].map(THREE.MathUtils.radToDeg);
    const [r, g, b] = srgb(part.node.color);
    objects += `	Geometry: ${geometryId}, "Geometry::${part.name}", "Mesh" {
		Vertices: *${pos.count * 3} {
			a: ${list(Array.from(pos.array))}
		}
		PolygonVertexIndex: *${indices.length} {
			a: ${indices.join(",")}
		}
		GeometryVersion: 124
		LayerElementNormal: 0 {
			Version: 101
			Name: ""
			MappingInformationType: "ByPolygonVertex"
			ReferenceInformationType: "Direct"
			Normals: *${normal.count * 3} {
				a: ${list(Array.from(normal.array))}
			}
		}
		LayerElementUV: 0 {
			Version: 101
			Name: "UVMap"
			MappingInformationType: "ByPolygonVertex"
			ReferenceInformationType: "IndexToDirect"
			UV: *${uv.count * 2} {
				a: ${list(Array.from(uv.array))}
			}
			UVIndex: *${uv.count} {
				a: ${Array.from({ length: uv.count }, (_, k) => k).join(",")}
			}
		}
		LayerElementMaterial: 0 {
			Version: 101
			Name: ""
			MappingInformationType: "AllSame"
			ReferenceInformationType: "IndexToDirect"
			Materials: *1 {
				a: 0
			}
		}
		Layer: 0 {
			Version: 100
			LayerElement:  {
				Type: "LayerElementNormal"
				TypedIndex: 0
			}
			LayerElement:  {
				Type: "LayerElementMaterial"
				TypedIndex: 0
			}
			LayerElement:  {
				Type: "LayerElementUV"
				TypedIndex: 0
			}
		}
	}
	Model: ${modelId}, "Model::${part.name}", "Mesh" {
		Version: 232
		Properties70:  {
			P: "RotationOrder", "enum", "", "",0
			P: "Lcl Translation", "Lcl Translation", "", "A",${list(part.node.position)}
			P: "Lcl Rotation", "Lcl Rotation", "", "A",${list(deg)}
			P: "Lcl Scaling", "Lcl Scaling", "", "A",${list(part.node.scale)}
			P: "DefaultAttributeIndex", "int", "Integer", "",0
		}
		Shading: T
		Culling: "CullingOff"
	}
	Material: ${materialId}, "Material::${part.name}_Mat", "" {
		Version: 102
		ShadingModel: "phong"
		MultiLayer: 0
		Properties70:  {
			P: "DiffuseColor", "Color", "", "A",${list([r, g, b])}
			P: "Diffuse", "Vector3D", "Vector", "",${list([r, g, b])}
		}
	}
`;
    connections += `	C: "OO",${modelId},0\n	C: "OO",${geometryId},${modelId}\n	C: "OO",${materialId},${modelId}\n`;
  });
  return `; FBX 7.4.0 project file
; Generated by OpenMindAI 3D Studio: ${scene.title.replace(/[\r\n]/g, " ")}
FBXHeaderExtension:  {
	FBXHeaderVersion: 1003
	FBXVersion: 7400
	Creator: "OpenMindAI 3D Studio"
}
GlobalSettings:  {
	Version: 1000
	Properties70:  {
		P: "UpAxis", "int", "Integer", "",1
		P: "UpAxisSign", "int", "Integer", "",1
		P: "FrontAxis", "int", "Integer", "",2
		P: "FrontAxisSign", "int", "Integer", "",1
		P: "CoordAxis", "int", "Integer", "",0
		P: "CoordAxisSign", "int", "Integer", "",1
		P: "OriginalUpAxis", "int", "Integer", "",1
		P: "OriginalUpAxisSign", "int", "Integer", "",1
		P: "UnitScaleFactor", "double", "Number", "",100
		P: "OriginalUnitScaleFactor", "double", "Number", "",100
	}
}
Definitions:  {
	Version: 100
	Count: ${parts.length * 3 + 1}
	ObjectType: "GlobalSettings" {
		Count: 1
	}
	ObjectType: "Geometry" {
		Count: ${parts.length}
	}
	ObjectType: "Model" {
		Count: ${parts.length}
	}
	ObjectType: "Material" {
		Count: ${parts.length}
	}
}
Objects:  {
${objects}}
Connections:  {
${connections}}
`;
}

/** Blender script: rebuilds the parts, saves a .blend and re-exports FBX/GLB. */
export function sceneToBlenderScript(scene: StudioScene): string {
  const parts = assetParts(scene).map((part) => ({
    name: part.name, shape: part.node.shape, position: part.node.position,
    rotation: part.node.rotation, scale: part.node.scale, color: part.node.color,
  }));
  return `# OpenMindAI 3D Studio - ${scene.title.replace(/[\r\n]/g, " ")}
# Run: blender --background --python build_in_blender.py
# Creates model.blend, model_blender.fbx and model_blender.glb next to this file.
import bpy, json, math, os
from mathutils import Euler, Matrix

PARTS = json.loads(${JSON.stringify(JSON.stringify(parts))})
OUT = os.path.dirname(os.path.abspath(__file__)) if "__file__" in globals() else os.getcwd()

def linear(channel):
    c = channel / 255
    return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4

bpy.ops.wm.read_factory_settings(use_empty=True)
# Scene data is Y-up (Unity/glTF); Blender is Z-up.
Y_UP_TO_Z_UP = Matrix.Rotation(math.radians(90), 4, "X")
for part in PARTS:
    shape = part["shape"]
    if shape == "sphere":
        bpy.ops.mesh.primitive_uv_sphere_add(segments=12, ring_count=8, radius=0.5)
    elif shape == "cylinder":
        bpy.ops.mesh.primitive_cylinder_add(vertices=12, radius=0.5, depth=1)
    elif shape == "cone":
        bpy.ops.mesh.primitive_cone_add(vertices=12, radius1=0.5, depth=1)
    elif shape == "torus":
        bpy.ops.mesh.primitive_torus_add(major_segments=16, minor_segments=8, major_radius=0.5, minor_radius=0.15)
    else:
        bpy.ops.mesh.primitive_cube_add(size=1)
    obj = bpy.context.active_object
    obj.name = obj.data.name = part["name"]
    if shape in ("cylinder", "cone"):
        # Blender builds these along Z; the scene expects their axis along Y.
        obj.data.transform(Matrix.Rotation(math.radians(-90), 4, "X"))
    rx, ry, rz = part["rotation"]
    local = (Matrix.Translation(part["position"])
             @ Euler((rx, ry, rz), "ZYX").to_matrix().to_4x4()
             @ Matrix.Diagonal((*part["scale"], 1)))
    obj.matrix_world = Y_UP_TO_Z_UP @ local
    for poly in obj.data.polygons:
        poly.use_smooth = False
    material = bpy.data.materials.new(part["name"] + "_Mat")
    material.use_nodes = True
    hexcolor = part["color"].lstrip("#")
    rgb = [linear(int(hexcolor[i:i + 2], 16)) for i in (0, 2, 4)]
    material.node_tree.nodes["Principled BSDF"].inputs["Base Color"].default_value = (*rgb, 1)
    obj.data.materials.append(material)

bpy.ops.wm.save_as_mainfile(filepath=os.path.join(OUT, "model.blend"))
bpy.ops.export_scene.fbx(filepath=os.path.join(OUT, "model_blender.fbx"), apply_unit_scale=True,
                         axis_forward="-Z", axis_up="Y", object_types={"MESH"}, mesh_smooth_type="FACE")
bpy.ops.export_scene.gltf(filepath=os.path.join(OUT, "model_blender.glb"), export_format="GLB", export_yup=True)
print("OpenMindAI asset written to", OUT)
`;
}

export function assetReadme(scene: StudioScene, parts: AssetPart[], previews: string[]): string {
  return `${scene.title}
${"=".repeat(Math.min(scene.title.length, 80))}

${scene.description}

Triangles: ${triangleCount(parts)}
Parts: ${parts.length} (${parts.map((p) => p.name).join(", ")})
Units: metres. Orientation: Y-up, Z-forward. Each part is a separate object with its pivot at its centre.
Shading: flat (faceted low-poly). Materials: one solid-colour PBR material per part. UVs: included on every mesh.

Files
- model.fbx: Unity-ready FBX 7.4 (drag into Assets).
- model.glb: glTF binary for Unity (glTFast), Unreal, Godot and the web.
- build_in_blender.py: run "blender --background --python build_in_blender.py" to create model.blend and re-export FBX/GLB.
- scene.json: editable OpenMindAI scene source.
${previews.length ? previews.map((p) => `- ${p}: preview render.`).join("\n") : "- Preview renders were not created because this browser has no WebGL."}

Note: this is a procedural primitive-based model. It is not sculpted, and texture maps are not baked.
`;
}
