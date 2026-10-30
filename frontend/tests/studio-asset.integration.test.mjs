import test from "node:test";
import assert from "node:assert/strict";
import * as THREE from "three";
import { FBXLoader } from "three/addons/loaders/FBXLoader.js";
import { assetParts, triangleCount, sceneToFbx, sceneToBlenderScript, assetReadme } from "../src/lib/studio-asset.ts";

const node = (id, shape, position, rotation, scale, color = "#33aaff") => ({ id, label: id, shape, position, rotation, scale, color });
const robot = { title: "Futuristic Robot", description: "Low-poly robot.", edges: [], nodes: [
  node("Torso", "box", [0, 1.2, 0], [0.1, 0.4, -0.2], [0.8, 1, 0.5]),
  node("Head", "sphere", [0, 2, 0], [0, 0, 0], [0.5, 0.5, 0.5], "#ffffff"),
  node("Left Arm", "cylinder", [-0.6, 1.2, 0], [0, 0, 0.3], [0.15, 0.8, 0.15]),
  node("Left Arm", "cylinder", [0.6, 1.2, 0], [0, 0, -0.3], [0.15, 0.8, 0.15]),
  node("Antenna", "cone", [0, 2.5, 0], [0, 0, 0], [0.1, 0.3, 0.1]),
  node("Ring", "torus", [0, 0.2, 0], [Math.PI / 2, 0, 0], [1, 1, 1]),
  { ...node("Hidden", "box", [0, 0, 0], [0, 0, 0], [1, 1, 1]), hidden: true },
] };

test("low-poly parts are flat, named uniquely and counted without degenerate triangles", () => {
  const parts = assetParts(robot);
  assert.deepEqual(parts.map(p => p.name), ["Torso", "Head", "Left_Arm", "Left_Arm_2", "Antenna", "Ring"]);
  const tris = triangleCount(parts);
  // box 12, sphere 12x8 (2 pole rows of 12 + 6 rows of 24), cylinder 24 sides + 24 caps, cone 12 + 12, torus 16x8x2.
  assert.equal(tris, 12 + 168 + 48 + 48 + 24 + 256);
  for (const part of parts) assert.ok(part.geometry.getAttribute("uv"), part.name + " has UVs");
});

test("FBX round-trips through three's FBXLoader with matching names, transforms and triangles", () => {
  const fbx = sceneToFbx(robot);
  assert.match(fbx, /^; FBX 7\.4\.0 project file/);
  assert.match(fbx, /"UpAxis", "int", "Integer", "",1/);
  const loaded = new FBXLoader().parse(new TextEncoder().encode(fbx).buffer, "");
  const meshes = [];
  loaded.traverse(o => o.isMesh && meshes.push(o));
  assert.deepEqual(meshes.map(m => m.name), ["Torso", "Head", "Left_Arm", "Left_Arm_2", "Antenna", "Ring"]);
  const loadedTris = meshes.reduce((sum, m) => sum + m.geometry.getAttribute("position").count / 3, 0);
  assert.equal(loadedTris, triangleCount(assetParts(robot)));
  // The rotation re-expressed for FBX must produce the same world matrix as three.js.
  const torso = meshes[0];
  const expected = new THREE.Matrix4().compose(new THREE.Vector3(0, 1.2, 0),
    new THREE.Quaternion().setFromEuler(new THREE.Euler(0.1, 0.4, -0.2, "XYZ")), new THREE.Vector3(0.8, 1, 0.5));
  torso.updateMatrixWorld(true);
  // FBXLoader applies the file's UnitScaleFactor (100 cm) to the root.
  const local = new THREE.Matrix4().copy(loaded.matrixWorld).invert().multiply(torso.matrixWorld);
  local.elements.forEach((v, i) => assert.ok(Math.abs(v - expected.elements[i]) < 1e-4, `matrix[${i}] ${v} vs ${expected.elements[i]}`));
  assert.equal(meshes[1].material.color.getHexString(), "ffffff");
});

test("Blender script and README describe the same parts without executable user text", () => {
  const evil = { ...robot, title: "Robot\nimport os; os.system('x')", nodes: [node("Part\"); os.system(\"x", "box", [0, 0, 0], [0, 0, 0], [1, 1, 1])] };
  const script = sceneToBlenderScript(evil);
  assert.doesNotMatch(script, /^import os; os\.system/m);
  assert.match(script, /PARTS = json\.loads\(/);
  assert.match(script, /"name\\":\\"Part_os_system_x\\"/);
  assert.match(script, /save_as_mainfile/);
  assert.match(script, /export_scene\.fbx/);
  const readme = assetReadme(robot, assetParts(robot), ["preview_front.png"]);
  assert.match(readme, /Triangles: 556/);
  assert.match(readme, /Y-up, Z-forward/);
});
