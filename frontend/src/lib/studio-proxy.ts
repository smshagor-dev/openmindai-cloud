const uuid = "[a-f0-9-]{36}";
const routes: Record<string, RegExp> = {
  GET: new RegExp(
    `^(3d-scenes/\\d+/revisions|3d-studio/(engines|projects|assets(?:/\\d+/(data|download))?|workflows|jobs(?:/${uuid})?))$`,
  ),
  POST: new RegExp(
    `^3d-studio/(route|assets(?:/\\d+/restore)?|projects|workflows(?:/run)?|jobs(?:/scene|/${uuid}/cancel)?)$`,
  ),
  PUT: /^(3d-scenes\/\d+|3d-studio\/workflows\/\d+)$/,
  DELETE: /^3d-studio\/assets\/\d+$/,
};
export const studioRoute = (method: string, path: string) =>
  routes[method]?.test(path) || false;
export const studioDownload = (path: string) =>
  /^3d-studio\/assets\/\d+\/download$/.test(path);
