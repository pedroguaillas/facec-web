import { z as z$1 } from "zod";
//#region src/registry/schema.ts
const registryConfigItemSchema = z$1.union([z$1.string().refine((s) => s.includes("{name}"), { message: "Registry URL must include {name} placeholder" }), z$1.object({
	url: z$1.string().refine((s) => s.includes("{name}"), { message: "Registry URL must include {name} placeholder" }),
	params: z$1.record(z$1.string(), z$1.string()).optional(),
	headers: z$1.record(z$1.string(), z$1.string()).optional()
})]);
const registryConfigSchema = z$1.record(z$1.string().refine((key) => key.startsWith("@"), { message: "Registry names must start with @ (e.g., @v0, @acme)" }), registryConfigItemSchema);
const rawConfigSchema = z$1.object({
	$schema: z$1.string().optional(),
	style: z$1.string(),
	font: z$1.string().optional(),
	fontHeading: z$1.string().optional(),
	typescript: z$1.coerce.boolean().default(true),
	tailwind: z$1.object({
		config: z$1.string().optional(),
		css: z$1.string(),
		baseColor: z$1.string(),
		cssVariables: z$1.boolean().default(true),
		prefix: z$1.string().default("").optional()
	}),
	iconLibrary: z$1.string().optional(),
	rtl: z$1.boolean().default(false).optional(),
	pointer: z$1.boolean().default(false).optional(),
	menuColor: z$1.enum([
		"default",
		"inverted",
		"default-translucent",
		"inverted-translucent"
	]).default("default").optional(),
	menuAccent: z$1.enum(["subtle", "bold"]).default("subtle").optional(),
	aliases: z$1.object({
		components: z$1.string(),
		utils: z$1.string(),
		ui: z$1.string().optional(),
		lib: z$1.string().optional(),
		hooks: z$1.string().optional(),
		composables: z$1.string().optional()
	}),
	registries: registryConfigSchema.optional()
}).strict();
const configSchema = rawConfigSchema.extend({ resolvedPaths: z$1.object({
	cwd: z$1.string(),
	tailwindConfig: z$1.string(),
	tailwindCss: z$1.string(),
	utils: z$1.string(),
	components: z$1.string(),
	lib: z$1.string(),
	hooks: z$1.string(),
	ui: z$1.string(),
	composables: z$1.string()
}) });
const workspaceConfigSchema = z$1.record(configSchema);
const registryItemTypeSchema = z$1.enum([
	"registry:lib",
	"registry:block",
	"registry:component",
	"registry:ui",
	"registry:hook",
	"registry:composable",
	"registry:page",
	"registry:file",
	"registry:theme",
	"registry:style",
	"registry:item",
	"registry:base",
	"registry:font",
	"registry:example",
	"registry:internal"
]);
const registryItemFileSchema = z$1.discriminatedUnion("type", [z$1.object({
	path: z$1.string(),
	content: z$1.string().optional(),
	type: z$1.enum(["registry:file", "registry:page"]),
	target: z$1.string()
}), z$1.object({
	path: z$1.string(),
	content: z$1.string().optional(),
	type: registryItemTypeSchema.exclude(["registry:file", "registry:page"]),
	target: z$1.string().optional()
})]);
const registryItemTailwindSchema = z$1.object({ config: z$1.object({
	content: z$1.array(z$1.string()).optional(),
	theme: z$1.record(z$1.string(), z$1.any()).optional(),
	plugins: z$1.array(z$1.string()).optional()
}).optional() });
const registryItemCssVarsSchema = z$1.object({
	theme: z$1.record(z$1.string(), z$1.string()).optional(),
	light: z$1.record(z$1.string(), z$1.string()).optional(),
	dark: z$1.record(z$1.string(), z$1.string()).optional()
});
const cssValueSchema = z$1.lazy(() => z$1.union([
	z$1.string(),
	z$1.array(z$1.union([z$1.string(), z$1.record(z$1.string(), z$1.string())])),
	z$1.record(z$1.string(), cssValueSchema)
]));
const registryItemCssSchema = z$1.record(z$1.string(), cssValueSchema);
const registryItemEnvVarsSchema = z$1.record(z$1.string(), z$1.string());
const registryItemFontSchema = z$1.object({
	family: z$1.string(),
	provider: z$1.literal("google"),
	import: z$1.string(),
	variable: z$1.string(),
	weight: z$1.array(z$1.string()).optional(),
	subsets: z$1.array(z$1.string()).optional()
});
const registryItemCommonSchema = z$1.object({
	$schema: z$1.string().optional(),
	extends: z$1.string().optional(),
	name: z$1.string(),
	title: z$1.string().optional(),
	author: z$1.string().min(2).optional(),
	description: z$1.string().optional(),
	dependencies: z$1.array(z$1.string()).optional(),
	devDependencies: z$1.array(z$1.string()).optional(),
	registryDependencies: z$1.array(z$1.string()).optional(),
	files: z$1.array(registryItemFileSchema).optional(),
	tailwind: registryItemTailwindSchema.optional(),
	cssVars: registryItemCssVarsSchema.optional(),
	css: registryItemCssSchema.optional(),
	envVars: registryItemEnvVarsSchema.optional(),
	meta: z$1.record(z$1.string(), z$1.any()).optional(),
	docs: z$1.string().optional(),
	categories: z$1.array(z$1.string()).optional()
});
const registryItemSchema = z$1.discriminatedUnion("type", [
	registryItemCommonSchema.extend({
		type: z$1.literal("registry:base"),
		config: rawConfigSchema.deepPartial().optional()
	}),
	registryItemCommonSchema.extend({
		type: z$1.literal("registry:font"),
		font: registryItemFontSchema
	}),
	registryItemCommonSchema.extend({ type: registryItemTypeSchema.exclude(["registry:base", "registry:font"]) })
]);
const registrySchema = z$1.object({
	name: z$1.string(),
	homepage: z$1.string(),
	items: z$1.array(registryItemSchema)
});
const registryIndexSchema = z$1.array(registryItemSchema);
const stylesSchema = z$1.array(z$1.object({
	name: z$1.string(),
	label: z$1.string()
}));
const iconsSchema = z$1.record(z$1.string(), z$1.record(z$1.string(), z$1.string()));
const registryBaseColorSchema = z$1.object({
	inlineColors: z$1.object({
		light: z$1.record(z$1.string(), z$1.string()),
		dark: z$1.record(z$1.string(), z$1.string())
	}),
	cssVars: registryItemCssVarsSchema,
	cssVarsV4: registryItemCssVarsSchema.optional(),
	inlineColorsTemplate: z$1.string(),
	cssVarsTemplate: z$1.string()
});
const registryResolvedItemsTreeSchema = registryItemCommonSchema.pick({
	dependencies: true,
	devDependencies: true,
	files: true,
	tailwind: true,
	cssVars: true,
	css: true,
	envVars: true,
	docs: true
}).extend({ fonts: z$1.array(registryItemCommonSchema.extend({
	type: z$1.literal("registry:font"),
	font: registryItemFontSchema
})).optional() });
const searchResultItemSchema = z$1.object({
	name: z$1.string(),
	type: z$1.string().optional(),
	description: z$1.string().optional(),
	registry: z$1.string(),
	addCommandArgument: z$1.string()
});
const searchResultsSchema = z$1.object({
	pagination: z$1.object({
		total: z$1.number(),
		offset: z$1.number(),
		limit: z$1.number(),
		hasMore: z$1.boolean()
	}),
	items: z$1.array(searchResultItemSchema)
});
const registriesIndexSchema = z$1.record(z$1.string().regex(/^@[a-z0-9][\w-]*$/i), z$1.string());
const presetSchema = z$1.object({
	name: z$1.string(),
	title: z$1.string(),
	description: z$1.string(),
	base: z$1.string(),
	style: z$1.string(),
	baseColor: z$1.string(),
	theme: z$1.string(),
	iconLibrary: z$1.string(),
	font: z$1.string(),
	menuAccent: z$1.enum(["subtle", "bold"]),
	menuColor: z$1.enum([
		"default",
		"inverted",
		"default-translucent",
		"inverted-translucent"
	]),
	radius: z$1.string()
});
const configJsonSchema = z$1.object({ presets: z$1.array(presetSchema) });
//#endregion
export { configJsonSchema, configSchema, iconsSchema, presetSchema, rawConfigSchema, registriesIndexSchema, registryBaseColorSchema, registryConfigItemSchema, registryConfigSchema, registryIndexSchema, registryItemCommonSchema, registryItemCssSchema, registryItemCssVarsSchema, registryItemEnvVarsSchema, registryItemFileSchema, registryItemFontSchema, registryItemSchema, registryItemTailwindSchema, registryItemTypeSchema, registryResolvedItemsTreeSchema, registrySchema, searchResultItemSchema, searchResultsSchema, stylesSchema, workspaceConfigSchema };

//# sourceMappingURL=index.js.map