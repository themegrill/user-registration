import { expect, test } from "@playwright/test";
import { execFile } from "node:child_process";
import path from "node:path";
import { promisify } from "node:util";

const run = promisify(execFile);

test("Products restriction survives a Whole Site target on the same rule @fresh @content-restriction", async () => {
	const root = path.resolve(__dirname, "../../..");
	const php = String.raw`
		error_reporting(E_ALL);
		set_error_handler(function ($severity, $message) { throw new ErrorException($message, 0, $severity); });
		function source_function($file, $name) {
			$tokens = token_get_all(file_get_contents($file));
			for ($i = 0; $i < count($tokens); ++$i) {
				if (!is_array($tokens[$i]) || T_FUNCTION !== $tokens[$i][0]) continue;
				$j = $i + 1;
				while (is_array($tokens[$j]) && T_WHITESPACE === $tokens[$j][0]) ++$j;
				if (!is_array($tokens[$j]) || $name !== $tokens[$j][1]) continue;
				$code = ''; $depth = 0; $started = false;
				for ($k = $i; $k < count($tokens); ++$k) {
					$token = $tokens[$k];
					$code .= is_array($token) ? $token[1] : $token;
					if ('{' === $token) { ++$depth; $started = true; }
					if ('}' === $token && $started && 0 === --$depth) return $code;
				}
			}
			throw new RuntimeException('Missing source function: ' . $name);
		}
		class WP_Post { public $ID = 42; public $post_type = 'page'; }
		function is_super_admin() { return false; }
		function absint($value) { return abs((int) $value); }
		function get_option($key, $default = false) { return 'woocommerce_shop_page_id' === $key ? 42 : $default; }
		function get_post_meta() { return false; }
		function ur_string_to_bool($value) { return (bool) $value; }
		function urcr_is_page_excluded() { return false; }
		function wp_list_pluck($list, $key) { return array_column($list, $key); }
		function is_singular() { return false; }
		function apply_filters($hook, $value) { return $value; }
		function do_action() {}
		function urcr_is_access_rule_enabled($rule) { return $rule['enabled']; }
		function urcr_is_action_specified($rule) { return !empty($rule['actions']); }
		function urcr_is_allow_access() { return $GLOBALS['logged_out']; }
		function urcr_apply_content_restriction() { ++$GLOBALS['applied']; }
		eval(source_function($argv[1], 'urcr_is_target_post'));
		eval('class RuleRunner { private function get_all_access_rules() { return $GLOBALS["rules"]; } public '
			. source_function($argv[2], 'advanced_restriction_wc_with_access_rule') . '}');
		function check_rule($targets, $logged_out, $expected) {
			$GLOBALS['logged_out'] = $logged_out;
			$GLOBALS['applied'] = 0;
			$GLOBALS['rules'] = array((object) array('post_content' => json_encode(array(
				'enabled' => true,
				'logic_map' => array('conditions' => array(array('type' => 'login_status'))),
				'target_contents' => $targets,
				'actions' => array(array('access_control' => 'restrict')),
			))));
			(new RuleRunner())->advanced_restriction_wc_with_access_rule('shop-template', new WP_Post());
			if ($expected !== $GLOBALS['applied']) throw new RuntimeException('Expected ' . $expected . ' restrictions, got ' . $GLOBALS['applied']);
		}
		$products = array('type' => 'post_types', 'value' => array('product'));
		$whole_site = array('type' => 'whole_site');
		check_rule(array($products), true, 1);
		check_rule(array($products, $whole_site), true, 1);
		check_rule(array($products, $whole_site), false, 0);
		check_rule(array($whole_site), true, 0);
		echo '4 assertions passed';
	`;
	const { stdout, stderr } = await run(process.env.UR_SECURITY_PHP_BINARY ?? "php", [
		"-r",
		php,
		path.join(root, "modules/content-restriction/functions-urcr-core.php"),
		path.join(root, "modules/content-restriction/class-urcr-frontend.php"),
	]);
	expect(stderr).toBe("");
	expect(stdout.trim()).toBe("4 assertions passed");
});
