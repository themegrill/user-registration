import {
	CheckSection,
	CheckStatus,
	DeliveryOutcome,
	HealthCheck,
	orderedSections,
	ScanSummary,
} from "../api/healthCheckupApi";

/** Strip the `**bold**` / `` `code` `` markers the UI renders, for a plain-text report. */
const plain = (text: string) => text.replace(/\*\*/g, "").replace(/`/g, "");

const statusLabels: Record<CheckStatus, string> = {
	pass: "PASS",
	error: "WILL FAIL",
	warning: "MAY FAIL",
	blocked: "BLOCKED",
	unknown: "CAN'T TELL",
};

const outcomeText: Record<DeliveryOutcome, string> = {
	arrived: "Arrived in inbox",
	spam: "Arrived, but filed as spam",
	none: "Never arrived (not in inbox or spam)",
};

const rule = "-".repeat(58);

/**
 * Read cold by a support agent with no access to the site, so it leads with the
 * answer, groups the checks as the admin saw them, and spells out every failing
 * one in full. Nothing here is sensitive; the scan never reads a password.
 */
export function buildReport(
	checks: HealthCheck[],
	outcome: DeliveryOutcome | null,
	summary?: ScanSummary | null,
	sections?: CheckSection[],
	dnsChecks?: HealthCheck[]
): string {
	const failing = checks.filter(
		(check) => check.status === "error" || check.status === "warning"
	);

	// Screen order, not server order: read alongside the admin's own description.
	// One unlabelled group covers a report built before a scan grouped anything.
	const groups: CheckSection[] =
		sections && sections.length > 0
			? orderedSections(sections)
			: [{ key: "delivery", title: "All checks", description: "", checks }];

	const lines: string[] = [
		"USER REGISTRATION & MEMBERSHIP: EMAIL HEALTH REPORT",
		"Generated from Settings → Emails → Health Checkup",
		rule,
		"",
	];

	if (summary) {
		lines.push(`RESULT: ${summary.title}`, plain(summary.message), "");
	}

	lines.push(`Checks run: ${checks.length}`, `Needing attention: ${failing.length}`, "");

	groups.forEach((group) => {
		lines.push(rule, group.title.toUpperCase(), rule);
		group.checks.forEach((check) => {
			lines.push(`[${statusLabels[check.status] ?? "ERROR"}] ${check.title}`);
		});
		lines.push("");
	});

	// SPF and DMARC stated rather than counted: an agent still needs the records.
	if (dnsChecks && dnsChecks.length > 0) {
		lines.push(
			rule,
			"SPF AND DMARC CHECK REPORT",
			rule,
			"For information only. Not counted in the issue total above.",
			""
		);

		dnsChecks.forEach((check) => {
			lines.push(
				`[${statusLabels[check.status] ?? "ERROR"}] ${check.title}`,
				`   ${plain(check.message).replace(/\n/g, "\n   ")}`
			);

			if (check.fix) {
				lines.push(`   Suggested fix: ${plain(check.fix)}`);
			}

			lines.push("");
		});
	}

	if (failing.length > 0) {
		lines.push(rule, "WHAT IS WRONG, IN DETAIL", rule);
		failing.forEach((check, index) => {
			lines.push(
				`${index + 1}. ${check.title}  [${statusLabels[check.status]}]`,
				`   ${plain(check.message).replace(/\n/g, "\n   ")}`
			);

			if (check.fix) {
				lines.push(`   Suggested fix: ${plain(check.fix)}`);
			}

			lines.push("");
		});
	}

	lines.push(
		rule,
		"LIVE DELIVERY TEST",
		rule,
		`Test email sent to: ${window._UR_EMAIL_HEALTH_.adminEmail}`,
		`Reported by admin:  ${outcome ? outcomeText[outcome] : "Not tested yet"}`,
		"",
		rule,
		"SITE INFO",
		rule,
		`Site URL:       ${window._UR_EMAIL_HEALTH_.siteUrl}`,
		`WordPress:      ${window._UR_EMAIL_HEALTH_.wpVersion}`,
		`PHP:            ${window._UR_EMAIL_HEALTH_.phpVersion}`,
		`Plugin version: ${window._UR_EMAIL_HEALTH_.pluginVersion}`
	);

	return lines.join("\n");
}
