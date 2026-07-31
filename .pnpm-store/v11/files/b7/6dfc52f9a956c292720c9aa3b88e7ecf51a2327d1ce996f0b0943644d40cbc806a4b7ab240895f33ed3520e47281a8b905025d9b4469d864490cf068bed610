import { n as transformIcons, t as transformMenu } from "../transform-menu-CLNZ5vUh.js";
import { z as z$1 } from "zod";
import { Node, Project, ScriptKind } from "ts-morph";
import postcss from "postcss";
import selectorParser from "postcss-selector-parser";
//#region src/styles/create-style-map.ts
const CN_PREFIX = "cn-";
const styleMapSchema = z$1.record(z$1.string().startsWith(CN_PREFIX), z$1.string());
function createStyleMap(input) {
	const root = postcss.parse(input);
	const result = {};
	root.walkRules((rule) => {
		const selectors = rule.selectors ?? [];
		if (selectors.length === 0) return;
		const tailwindClasses = extractTailwindClasses(rule);
		if (!tailwindClasses) return;
		for (const selector of selectors) {
			const normalizedSelector = normalizeSelector(selector);
			selectorParser((selectorsRoot) => {
				selectorsRoot.each((sel) => {
					const targetClass = findSubjectClass(sel);
					if (!targetClass) return;
					const className = targetClass.value;
					if (!className.startsWith(CN_PREFIX)) return;
					result[className] = result[className] ? `${tailwindClasses} ${result[className]}` : tailwindClasses;
				});
			}).processSync(normalizedSelector);
		}
	});
	return styleMapSchema.parse(result);
}
function normalizeSelector(selector) {
	return selector.replace(/\s*&\s*/g, "").trim();
}
function extractTailwindClasses(rule) {
	const classes = [];
	for (const node of rule.nodes || []) if (node.type === "atrule" && node.name === "apply") {
		const value = node.params.trim();
		if (value) classes.push(value);
	}
	if (classes.length === 0) return null;
	return classes.join(" ");
}
function findSubjectClass(selector) {
	const classNodes = [];
	selector.walkClasses((classNode) => {
		if (classNode.value.startsWith(CN_PREFIX)) classNodes.push(classNode);
	});
	if (classNodes.length === 0) return null;
	return classNodes[classNodes.length - 1];
}
//#endregion
//#region src/styles/transform-style-map.ts
/**
* Classes that should never be removed during transformation.
* These are typically used as CSS selectors or for other purposes
* that require the class name to remain in the code.
*/
const ALLOWLIST = /* @__PURE__ */ new Set(["cn-menu-target"]);
function isStringLiteralLike(node) {
	return Node.isStringLiteral(node) || Node.isNoSubstitutionTemplateLiteral(node);
}
const transformStyleMap = async ({ sourceFile, styleMap }) => {
	const matchedClasses = /* @__PURE__ */ new Set();
	applyToCvaCalls(sourceFile, styleMap, matchedClasses);
	applyToClassNameAttributes(sourceFile, styleMap, matchedClasses);
	applyToMergePropsCalls(sourceFile, styleMap, matchedClasses);
	return sourceFile;
};
function applyStyleToCvaString(stringNode, styleMap, matchedClasses) {
	const stringValue = stringNode.getLiteralText();
	const cnClasses = extractCnClasses(stringValue);
	if (cnClasses.length === 0) return;
	const unmatchedClasses = cnClasses.filter((cnClass) => !matchedClasses.has(cnClass));
	if (unmatchedClasses.length === 0) {
		const updated = removeCnClasses(stringValue);
		stringNode.setLiteralValue(updated);
		return;
	}
	const tailwindClassesToApply = unmatchedClasses.map((cnClass) => styleMap[cnClass]).filter((classes) => Boolean(classes));
	if (tailwindClassesToApply.length > 0) {
		const updated = removeCnClasses(mergeClasses(tailwindClassesToApply.join(" "), stringValue));
		stringNode.setLiteralValue(updated);
		unmatchedClasses.forEach((cnClass) => matchedClasses.add(cnClass));
	} else {
		const updated = removeCnClasses(stringValue);
		stringNode.setLiteralValue(updated);
	}
}
function applyToCvaCalls(sourceFile, styleMap, matchedClasses) {
	sourceFile.forEachDescendant((node) => {
		if (!Node.isCallExpression(node)) return;
		const expression = node.getExpression();
		if (!Node.isIdentifier(expression) || expression.getText() !== "cva") return;
		const baseArg = node.getArguments()[0];
		if (Node.isStringLiteral(baseArg)) applyStyleToCvaString(baseArg, styleMap, matchedClasses);
		const configArg = node.getArguments()[1];
		if (!configArg || !Node.isObjectLiteralExpression(configArg)) return;
		const variantsProp = configArg.getProperties().find((prop) => Node.isPropertyAssignment(prop) && Node.isIdentifier(prop.getNameNode()) && prop.getNameNode().getText() === "variants");
		if (!variantsProp || !Node.isPropertyAssignment(variantsProp)) return;
		const variantsObj = variantsProp.getInitializer();
		if (!variantsObj || !Node.isObjectLiteralExpression(variantsObj)) return;
		variantsObj.getProperties().forEach((typeProp) => {
			if (!Node.isPropertyAssignment(typeProp)) return;
			const typeObj = typeProp.getInitializer();
			if (!typeObj || !Node.isObjectLiteralExpression(typeObj)) return;
			typeObj.getProperties().forEach((variantProp) => {
				if (!Node.isPropertyAssignment(variantProp)) return;
				const variantValue = variantProp.getInitializer();
				if (variantValue && Node.isStringLiteral(variantValue)) applyStyleToCvaString(variantValue, styleMap, matchedClasses);
			});
		});
	});
}
function applyToClassNameAttributes(sourceFile, styleMap, matchedClasses) {
	sourceFile.forEachDescendant((node) => {
		if (!Node.isJsxAttribute(node) || node.getNameNode().getText() !== "className") return;
		const initializer = node.getInitializer();
		if (!initializer) return;
		const cnClasses = extractCnClassesFromAttribute(initializer);
		if (cnClasses.length === 0) return;
		const jsxElement = node.getParent()?.getParent();
		if (!jsxElement || !Node.isJsxOpeningElement(jsxElement) && !Node.isJsxSelfClosingElement(jsxElement)) return;
		const unmatchedClasses = cnClasses.filter((cnClass) => !matchedClasses.has(cnClass));
		if (unmatchedClasses.length === 0) {
			cleanCnClassesFromAttribute(initializer);
			return;
		}
		const tailwindClassesToApply = unmatchedClasses.map((cnClass) => styleMap[cnClass]).filter((classes) => Boolean(classes));
		if (tailwindClassesToApply.length > 0) applyClassesToElement(jsxElement, tailwindClassesToApply.join(" "));
		else cleanCnClassesFromAttribute(initializer);
	});
}
function extractCnClassesFromAttribute(initializer) {
	const classes = [];
	if (isStringLiteralLike(initializer)) return extractCnClasses(initializer.getLiteralText());
	if (!Node.isJsxExpression(initializer)) return classes;
	const expression = initializer.getExpression();
	if (!expression) return classes;
	if (isStringLiteralLike(expression)) return extractCnClasses(expression.getLiteralText());
	if (Node.isCallExpression(expression) && isCnCall(expression)) {
		for (const argument of expression.getArguments()) if (isStringLiteralLike(argument)) classes.push(...extractCnClasses(argument.getLiteralText()));
	}
	return classes;
}
function cleanCnClassesFromAttribute(initializer) {
	if (isStringLiteralLike(initializer)) {
		const cleaned = removeCnClasses(initializer.getLiteralText());
		initializer.setLiteralValue(cleaned);
		return;
	}
	if (!Node.isJsxExpression(initializer)) return;
	const expression = initializer.getExpression();
	if (!expression) return;
	if (isStringLiteralLike(expression)) {
		const cleaned = removeCnClasses(expression.getLiteralText());
		expression.setLiteralValue(cleaned);
		return;
	}
	if (Node.isCallExpression(expression) && isCnCall(expression)) {
		for (const argument of expression.getArguments()) if (isStringLiteralLike(argument)) {
			const cleaned = removeCnClasses(argument.getLiteralText());
			argument.setLiteralValue(cleaned);
		}
		removeEmptyArgumentsFromCnCall(expression);
	}
}
function extractCnClasses(str) {
	const matches = str.matchAll(/\bcn-[\w-]+\b/g);
	return Array.from(matches, (match) => match[0]);
}
function removeCnClasses(str) {
	return str.replace(/\bcn-[\w-]+\b/g, (match) => {
		if (ALLOWLIST.has(match)) return match;
		return "";
	}).replace(/\s+/g, " ").trim();
}
function removeEmptyArgumentsFromCnCall(callExpression) {
	if (!isCnCall(callExpression)) return;
	const args = callExpression.getArguments();
	const nonEmptyArgs = args.filter((arg) => {
		if (isStringLiteralLike(arg)) return arg.getLiteralText().trim() !== "";
		return true;
	});
	if (nonEmptyArgs.length !== args.length) {
		const argTexts = nonEmptyArgs.map((arg) => arg.getText());
		const parent = callExpression.getParent();
		if (parent && Node.isJsxExpression(parent)) parent.replaceWithText(`{cn(${argTexts.join(", ")})}`);
		else callExpression.replaceWithText(`cn(${argTexts.join(", ")})`);
	}
}
function applyClassesToElement(element, tailwindClasses) {
	if (!Node.isJsxOpeningElement(element) && !Node.isJsxSelfClosingElement(element)) return;
	const attribute = element.getAttributes().find((attr) => Node.isJsxAttribute(attr) && attr.getNameNode().getText() === "className");
	if (!attribute || !Node.isJsxAttribute(attribute)) {
		element.addAttribute({
			name: "className",
			initializer: `{cn(${JSON.stringify(tailwindClasses)})}`
		});
		return;
	}
	const initializer = attribute.getInitializer();
	if (!initializer) {
		attribute.setInitializer(`{cn(${JSON.stringify(tailwindClasses)})}`);
		return;
	}
	if (isStringLiteralLike(initializer)) {
		const updated = removeCnClasses(mergeClasses(tailwindClasses, initializer.getLiteralText()));
		initializer.setLiteralValue(updated);
		return;
	}
	if (!Node.isJsxExpression(initializer)) return;
	const expression = initializer.getExpression();
	if (!expression) {
		attribute.setInitializer(`{cn(${JSON.stringify(tailwindClasses)})}`);
		return;
	}
	if (isStringLiteralLike(expression)) {
		const updated = removeCnClasses(mergeClasses(tailwindClasses, expression.getLiteralText()));
		expression.setLiteralValue(updated);
		return;
	}
	if (Node.isCallExpression(expression) && isCnCall(expression)) {
		const firstArg = expression.getArguments()[0];
		if (isStringLiteralLike(firstArg)) {
			const updated = removeCnClasses(mergeClasses(tailwindClasses, firstArg.getLiteralText()));
			firstArg.setLiteralValue(updated);
			for (let i = 1; i < expression.getArguments().length; i++) {
				const arg = expression.getArguments()[i];
				if (isStringLiteralLike(arg)) {
					const argText = arg.getLiteralText();
					const cleaned = removeCnClasses(argText);
					if (cleaned !== argText) arg.setLiteralValue(cleaned);
				}
			}
			removeEmptyArgumentsFromCnCall(expression);
			return;
		}
		const argumentTexts = expression.getArguments().map((argument) => {
			if (isStringLiteralLike(argument)) {
				const cleaned = removeCnClasses(argument.getLiteralText());
				return cleaned ? JSON.stringify(cleaned) : null;
			}
			return argument.getText();
		}).filter((arg) => arg !== null);
		const updatedArguments = [JSON.stringify(tailwindClasses), ...argumentTexts];
		attribute.setInitializer(`{cn(${updatedArguments.join(", ")})}`);
		return;
	}
	attribute.setInitializer(`{cn(${JSON.stringify(tailwindClasses)}, ${expression.getText()})}`);
}
function mergeClasses(newClasses, existing) {
	const existingParts = existing.split(/\s+/).filter(Boolean);
	return [...newClasses.split(/\s+/).filter(Boolean), ...existingParts].join(" ").trim();
}
function isCnCall(call) {
	const expression = call.getExpression();
	return Node.isIdentifier(expression) && expression.getText() === "cn";
}
function applyToMergePropsCalls(sourceFile, styleMap, matchedClasses) {
	sourceFile.forEachDescendant((node) => {
		if (!Node.isCallExpression(node)) return;
		const expression = node.getExpression();
		if (!Node.isIdentifier(expression) || expression.getText() !== "mergeProps") return;
		for (const arg of node.getArguments()) {
			if (!Node.isObjectLiteralExpression(arg)) continue;
			const classNameProp = arg.getProperties().find((prop) => Node.isPropertyAssignment(prop) && Node.isIdentifier(prop.getNameNode()) && prop.getNameNode().getText() === "className");
			if (!classNameProp || !Node.isPropertyAssignment(classNameProp)) continue;
			const classNameInitializer = classNameProp.getInitializer();
			if (!classNameInitializer) continue;
			if (Node.isCallExpression(classNameInitializer) && isCnCall(classNameInitializer)) {
				const cnClasses = extractCnClassesFromCnCall(classNameInitializer);
				if (cnClasses.length === 0) continue;
				const unmatchedClasses = cnClasses.filter((cnClass) => !matchedClasses.has(cnClass));
				if (unmatchedClasses.length === 0) {
					cleanCnClassesFromCnCall(classNameInitializer);
					continue;
				}
				const tailwindClassesToApply = unmatchedClasses.map((cnClass) => styleMap[cnClass]).filter((classes) => Boolean(classes));
				if (tailwindClassesToApply.length > 0) applyClassesToCnCall(classNameInitializer, tailwindClassesToApply.join(" "), matchedClasses, unmatchedClasses);
				else cleanCnClassesFromCnCall(classNameInitializer);
			}
		}
	});
}
function extractCnClassesFromCnCall(cnCall) {
	const classes = [];
	for (const argument of cnCall.getArguments()) if (isStringLiteralLike(argument)) classes.push(...extractCnClasses(argument.getLiteralText()));
	return classes;
}
function cleanCnClassesFromCnCall(cnCall) {
	for (const argument of cnCall.getArguments()) if (isStringLiteralLike(argument)) {
		const cleaned = removeCnClasses(argument.getLiteralText());
		argument.setLiteralValue(cleaned);
	}
	removeEmptyArgumentsFromCnCall(cnCall);
}
function applyClassesToCnCall(cnCall, tailwindClasses, matchedClasses, unmatchedClasses) {
	const firstArg = cnCall.getArguments()[0];
	if (isStringLiteralLike(firstArg)) {
		const updated = removeCnClasses(mergeClasses(tailwindClasses, firstArg.getLiteralText()));
		firstArg.setLiteralValue(updated);
		unmatchedClasses.forEach((cnClass) => matchedClasses.add(cnClass));
		for (let i = 1; i < cnCall.getArguments().length; i++) {
			const arg = cnCall.getArguments()[i];
			if (isStringLiteralLike(arg)) {
				const argText = arg.getLiteralText();
				const cleaned = removeCnClasses(argText);
				if (cleaned !== argText) arg.setLiteralValue(cleaned);
			}
		}
		removeEmptyArgumentsFromCnCall(cnCall);
		return;
	}
	const argumentTexts = cnCall.getArguments().map((argument) => {
		if (isStringLiteralLike(argument)) {
			const cleaned = removeCnClasses(argument.getLiteralText());
			return cleaned ? JSON.stringify(cleaned) : null;
		}
		return argument.getText();
	}).filter((arg) => arg !== null);
	const updatedArguments = [JSON.stringify(tailwindClasses), ...argumentTexts];
	unmatchedClasses.forEach((cnClass) => matchedClasses.add(cnClass));
	if (cnCall.getParent()) cnCall.replaceWithText(`cn(${updatedArguments.join(", ")})`);
}
//#endregion
//#region src/styles/transform.ts
async function transformStyle(source, { styleMap, transformers = [transformStyleMap] }) {
	const sourceFile = new Project({ useInMemoryFileSystem: true }).createSourceFile("component.tsx", source, {
		scriptKind: ScriptKind.TSX,
		overwrite: true
	});
	for (const transformer of transformers) await transformer({
		sourceFile,
		styleMap
	});
	return sourceFile.getText();
}
//#endregion
export { createStyleMap, transformIcons, transformMenu, transformStyle };

//# sourceMappingURL=index.js.map