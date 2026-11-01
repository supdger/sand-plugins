import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const portalRoot = resolve(fileURLToPath(new URL("..", import.meta.url)));
const outputDirectory = resolve(portalRoot, "../plugin/sand-iam/public/account");
const sourceIndex = resolve(portalRoot, "index.html");

await rm(outputDirectory, { recursive: true, force: true });
await mkdir(outputDirectory, { recursive: true });
await build({
  entryPoints: [resolve(portalRoot, "src/app.ts")],
  bundle: true,
  format: "esm",
  target: ["es2022"],
  outfile: resolve(outputDirectory, "account.js"),
  legalComments: "none",
});
const index = await readFile(sourceIndex, "utf8");
const builtIndex = index.replace('src="./src/app.ts"', 'src="./account.js"');
if (builtIndex === index) {
  throw new Error("portal index does not contain the source module marker");
}
await writeFile(resolve(outputDirectory, "index.html"), builtIndex, "utf8");
console.log(`SandIAM account portal built: ${outputDirectory}`);
