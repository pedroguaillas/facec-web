//#region src/preset/preset.ts
const PRESET_BASES = ["reka"];
const PRESET_STYLES = [
	"vega",
	"nova",
	"maia",
	"lyra",
	"mira",
	"luma",
	"sera",
	"rhea"
];
const PRESET_BASE_COLORS = [
	"neutral",
	"stone",
	"zinc",
	"mauve",
	"olive",
	"mist",
	"taupe"
];
const PRESET_THEMES = [
	"neutral",
	"stone",
	"zinc",
	"mauve",
	"olive",
	"mist",
	"taupe",
	"amber",
	"blue",
	"cyan",
	"emerald",
	"fuchsia",
	"green",
	"indigo",
	"lime",
	"orange",
	"pink",
	"purple",
	"red",
	"rose",
	"sky",
	"teal",
	"violet",
	"yellow"
];
const PRESET_ICON_LIBRARIES = [
	"lucide",
	"tabler",
	"hugeicons",
	"phosphor",
	"remixicon"
];
const PRESET_FONTS = [
	"inter",
	"geist-sans",
	"noto-sans",
	"nunito-sans",
	"figtree",
	"roboto",
	"raleway",
	"dm-sans",
	"public-sans",
	"outfit",
	"jetbrains-mono",
	"playfair-display",
	"oxanium",
	"manrope",
	"space-grotesk",
	"montserrat",
	"ibm-plex-sans",
	"source-sans-3",
	"instrument-sans",
	"geist-mono",
	"noto-serif",
	"roboto-slab",
	"merriweather",
	"lora"
];
const PRESET_FONT_HEADINGS = ["inherit", ...PRESET_FONTS];
const PRESET_CHART_COLORS = PRESET_THEMES;
const PRESET_RADII = [
	"default",
	"none",
	"small",
	"medium",
	"large"
];
const PRESET_MENU_ACCENTS = ["subtle", "bold"];
const PRESET_MENU_COLORS = [
	"default",
	"inverted",
	"default-translucent",
	"inverted-translucent"
];
const PRESET_FIELDS_V1 = [
	{
		key: "menuColor",
		values: PRESET_MENU_COLORS,
		bits: 3
	},
	{
		key: "menuAccent",
		values: PRESET_MENU_ACCENTS,
		bits: 2
	},
	{
		key: "radius",
		values: PRESET_RADII,
		bits: 3
	},
	{
		key: "font",
		values: PRESET_FONTS,
		bits: 5
	},
	{
		key: "fontHeading",
		values: PRESET_FONT_HEADINGS,
		bits: 5
	},
	{
		key: "iconLibrary",
		values: PRESET_ICON_LIBRARIES,
		bits: 3
	},
	{
		key: "theme",
		values: PRESET_THEMES,
		bits: 5
	},
	{
		key: "baseColor",
		values: PRESET_BASE_COLORS,
		bits: 3
	},
	{
		key: "style",
		values: PRESET_STYLES,
		bits: 3
	},
	{
		key: "base",
		values: PRESET_BASES,
		bits: 2
	},
	{
		key: "chartColor",
		values: PRESET_CHART_COLORS,
		bits: 5
	}
];
const DEFAULT_PRESET_CONFIG = Object.fromEntries(PRESET_FIELDS_V1.map((f) => [f.key, f.values[0]]));
const BASE62 = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz";
const CURRENT_VERSION = "a";
const VALID_VERSIONS = ["a"];
function toBase62(num) {
	if (num === 0) return "0";
	let result = "";
	let n = num;
	while (n > 0) {
		result = BASE62[n % 62] + result;
		n = Math.floor(n / 62);
	}
	return result;
}
function fromBase62(str) {
	let result = 0;
	for (let i = 0; i < str.length; i++) {
		const idx = BASE62.indexOf(str[i]);
		if (idx === -1) return -1;
		result = result * 62 + idx;
	}
	return result;
}
function encodePreset(config) {
	const merged = {
		...DEFAULT_PRESET_CONFIG,
		...config
	};
	let bits = 0;
	let offset = 0;
	for (const field of PRESET_FIELDS_V1) {
		const idx = field.values.indexOf(merged[field.key]);
		bits += (idx === -1 ? 0 : idx) * 2 ** offset;
		offset += field.bits;
	}
	return CURRENT_VERSION + toBase62(bits);
}
function decodePreset(code) {
	if (!code || code.length < 2) return null;
	const version = code[0];
	if (!VALID_VERSIONS.includes(version)) return null;
	const bits = fromBase62(code.slice(1));
	if (bits < 0) return null;
	const result = {};
	let offset = 0;
	for (const field of PRESET_FIELDS_V1) {
		const idx = Math.floor(bits / 2 ** offset) % 2 ** field.bits;
		result[field.key] = idx < field.values.length ? field.values[idx] : field.values[0];
		offset += field.bits;
	}
	return result;
}
function isPresetCode(value) {
	if (!value || value.length < 2 || value.length > 10) return false;
	if (!VALID_VERSIONS.includes(value[0])) return false;
	for (let i = 1; i < value.length; i++) if (!BASE62.includes(value[i])) return false;
	return true;
}
function isValidPreset(code) {
	return decodePreset(code) !== null;
}
function generateRandomConfig() {
	const pick = (arr) => arr[Math.floor(Math.random() * arr.length)];
	return Object.fromEntries(PRESET_FIELDS_V1.map((f) => [f.key, pick(f.values)]));
}
function generateRandomPreset() {
	return encodePreset(generateRandomConfig());
}
//#endregion
export { generateRandomPreset as _, PRESET_FONTS as a, toBase62 as b, PRESET_MENU_ACCENTS as c, PRESET_STYLES as d, PRESET_THEMES as f, generateRandomConfig as g, fromBase62 as h, PRESET_CHART_COLORS as i, PRESET_MENU_COLORS as l, encodePreset as m, PRESET_BASES as n, PRESET_FONT_HEADINGS as o, decodePreset as p, PRESET_BASE_COLORS as r, PRESET_ICON_LIBRARIES as s, DEFAULT_PRESET_CONFIG as t, PRESET_RADII as u, isPresetCode as v, isValidPreset as y };

//# sourceMappingURL=preset-B3__-ysK.js.map