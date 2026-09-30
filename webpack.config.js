const path = require("path");
const webpack = require("webpack");
const CopyWebpackPlugin = require("copy-webpack-plugin");
const defaults = require("@wordpress/scripts/config/webpack.config");
const PhpFilePathsPlugin = require("@wordpress/scripts/plugins/php-file-paths-plugin/index");
const { getProjectSourcePath } = require("@wordpress/scripts/utils/index");
const { realpathSync } = require("fs");

const isProEnabled =
	process.env.UR_PRO === "true" || process.env.UR_PRO === true;

module.exports = {
	...defaults,
	output: {
		...defaults.output,
		filename: "[name].js",
		path: path.resolve(__dirname + "/chunks")
	},
	entry: {
		welcome: "./src/welcome/index.tsx",
		dashboard: "./src/dashboard/index.js",
		blocks: "./src/blocks/index.js",
		formblock: "./assets/js/admin/gutenberg/form-block.js",
		form_templates: "./src/form-templates/index.js",
		"divi-builder": "./src/widgets/divi-builder/index.js",
		"content-access-rules": "./src/content-restriction/index.js",
		analytics: "./src/analytics/main.tsx",
		"health-checkup": "./src/health-checkup/index.tsx"
	},
	plugins: [
		...defaults.plugins.filter((plugin) => {
			return plugin.constructor.name !== "CopyPlugin";
		}),
		new webpack.DefinePlugin({
			"process.env.UR_PRO": JSON.stringify(
				isProEnabled ? "true" : "false"
			)
		}),
		...(isProEnabled
			? []
			: [
					new webpack.IgnorePlugin({
						resourceRegExp: /^\.\/modules\/pro$/,
						contextRegExp: /src\/widgets\/divi-builder$/
					}),
					new webpack.IgnorePlugin({
						resourceRegExp: /^\.\/blocks\/pro\/blocks$/,
						contextRegExp: /src\/blocks$/
					})
				]),
		new CopyWebpackPlugin({
			patterns: [
				{
					from: "**/block.json",
					to: ({ context, absoluteFilename }) => {
						const parentDir = path.basename(
							path.dirname(absoluteFilename)
						);
						return `${parentDir}/block.json`;
					},
					context: "src"
				},
				{
					from: "**/*.php",
					context: getProjectSourcePath(),
					noErrorOnMissing: true,
					filter: (filepath) => {
						return (
							process.env.WP_COPY_PHP_FILES_TO_DIST ||
							PhpFilePathsPlugin.paths.includes(
								realpathSync(filepath).replace(/\\/g, "/")
							)
						);
					}
				}
			]
		})
	]
};
