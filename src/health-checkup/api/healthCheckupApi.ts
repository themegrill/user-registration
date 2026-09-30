// A one-click resolution the scan row can offer for a failing check.
export interface CheckAction {
	type: "activate" | "install_smartsmtp" | "link";
	label: string;
	/** Plugin file to activate, for type "activate". */
	plugin?: string;
	/** Destination, for type "link". */
	url?: string;
}

/**
 * "error"   — mail will fail or be rejected.
 * "warning" — mail may be filtered, or a setting is off on purpose.
 * "blocked" — the setting is on but "Disable Emails" stops it; nothing here to fix.
 * "unknown" — we couldn't verify it (no DNS, local site), which is not the same as a failure.
 */
export type CheckStatus = "pass" | "error" | "warning" | "blocked" | "unknown";

export interface HealthCheck {
	key: string;
	title: string;
	status: CheckStatus;
	message: string;
	fix: string;
	action?: CheckAction;
}

export interface CheckSection {
	key: "delivery" | "settings";
	title: string;
	description: string;
	checks: HealthCheck[];
}

/** The one answer the admin actually wants: will these emails arrive? */
export interface ScanSummary {
	level: "pass" | "warning" | "error";
	title: string;
	message: string;
}

export type SmartSmtpStatus = "active" | "inactive" | "not_installed";

export interface SmtpPluginInfo {
	slug: string;
	name: string;
	is_smartsmtp: boolean;
}

export interface ScanResult {
	sections: CheckSection[];
	/** Feeds the report's headline; not rendered on any step. */
	summary: ScanSummary;
	/** Every check flattened, for the support report and the result screen. */
	checks: HealthCheck[];
	/**
	 * SPF and DMARC. Report-only and absent from `checks`, so never counted as
	 * issues. Optional: an older restored payload won't carry it.
	 */
	dns_checks?: HealthCheck[];
	smartsmtp_status: SmartSmtpStatus;
	smtp_plugin: SmtpPluginInfo | null;
}

export type DeliveryOutcome = "arrived" | "spam" | "none";

/**
 * Presentation order, settings before delivery. The server returns them
 * delivery-first, so this is the one place that decides; the report reads it too.
 */
export const SECTION_ORDER: CheckSection["key"][] = ["settings", "delivery"];

/** The section a scan step renders, or null when the scan hasn't returned yet. */
export function sectionOf(
	sections: CheckSection[],
	key: CheckSection["key"]
): CheckSection | null {
	return sections.find((section) => section.key === key) ?? null;
}

/** Sections in screen order, keeping any the server adds later. */
export function orderedSections(sections: CheckSection[]): CheckSection[] {
	const known = SECTION_ORDER.map((key) => sectionOf(sections, key)).filter(
		(section): section is CheckSection => null !== section
	);
	const rest = sections.filter((section) => !SECTION_ORDER.includes(section.key));

	return [...known, ...rest];
}

const config = () => window._UR_EMAIL_HEALTH_;

async function postForm<T>(body: URLSearchParams): Promise<T> {
	const response = await fetch(config().ajaxURL, {
		method: "POST",
		headers: { "Content-Type": "application/x-www-form-urlencoded" },
		body,
	});

	const result = await response.json();

	if (!result.success) {
		throw new Error(result.data?.message || "Request failed.");
	}

	return result.data as T;
}

export function runScan(): Promise<ScanResult> {
	return postForm<ScanResult>(
		new URLSearchParams({
			action: "user_registration_email_health_scan",
			nonce: config().scanNonce,
		})
	);
}

export function sendTestEmail(email: string): Promise<{ message: string }> {
	return postForm<{ message: string }>(
		new URLSearchParams({
			action: "user_registration_send_test_email",
			email,
			nonce: config().testEmailNonce,
		})
	);
}

export function confirmDelivery(result: DeliveryOutcome): Promise<void> {
	return postForm<void>(
		new URLSearchParams({
			action: "user_registration_email_health_confirm_delivery",
			result,
			nonce: config().confirmNonce,
		})
	);
}

export function installSmartSmtp(): Promise<{ redirect: string }> {
	return postForm<{ redirect: string }>(
		new URLSearchParams({
			action: "user_registration_email_health_install_smartsmtp",
			nonce: config().scanNonce,
		})
	);
}

export function activateSmtpPlugin(plugin: string): Promise<{ name: string }> {
	return postForm<{ name: string }>(
		new URLSearchParams({
			action: "user_registration_email_health_activate_smtp_plugin",
			plugin,
			nonce: config().scanNonce,
		})
	);
}
