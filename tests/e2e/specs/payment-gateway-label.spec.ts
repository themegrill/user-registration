import { expect, test } from "@playwright/test";
import { execFile } from "node:child_process";
import path from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);

test("PayPal REST keeps its slug and displays the PayPal brand @fresh", async () => {
	const source = path.resolve(__dirname, "../../../includes/functions-ur-core.php");
	const php = `
		function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
		function __($value) { return $value; }
		function apply_filters($hook, $value) { return $value; }
		function get_option($key, $default = array()) { return $default; }
		$source = file_get_contents($argv[1]);
		$start = strpos($source, "if ( ! function_exists( 'ur_get_payment_gateway_label' ) ) {");
		$end = strpos($source, "if ( ! function_exists( 'ur_has_payment_entries' ) ) {", $start);
		if (false === $start || false === $end) { exit(1); }
		eval(substr($source, $start, $end - $start));
		echo json_encode(array(
			'paypal_rest' => ur_get_payment_gateway_label('paypal_rest'),
			'paypal_standard' => ur_get_payment_gateway_label('paypal_standard'),
		));
	`;
	const { stdout, stderr } = await run(process.env.UR_SECURITY_PHP_BINARY ?? "php", ["-r", php, source]);
	expect(stderr).toBe("");
	expect(JSON.parse(stdout)).toEqual({
		paypal_rest: "PayPal",
		paypal_standard: "PayPal Standard",
	});
});
