/**
 * Allowed utm_medium values for outbound marketing links.
 */
export const UR_UTM_ALLOWED_MEDIUMS = [
	"upgrade-link",
	"button",
	"popup",
	"menu-link",
	"notice"
];

/**
 * Resolve the active UTM campaign from localized admin data.
 *
 * @returns {string}
 */
export const getUrUtmCampaign = () => {
	if (typeof window === "undefined") {
		return "lite-version";
	}

	if (window.ur_utm && window.ur_utm.campaign) {
		return String(window.ur_utm.campaign);
	}

	if (
		typeof window._UR_DASHBOARD_ !== "undefined" &&
		window._UR_DASHBOARD_.utmCampaign
	) {
		return String(window._UR_DASHBOARD_.utmCampaign);
	}

	if (
		typeof window.urcr_localized_data !== "undefined" &&
		window.urcr_localized_data.utm_campaign
	) {
		return String(window.urcr_localized_data.utm_campaign);
	}

	if (
		typeof window.__UR_ANALYTICS__ !== "undefined" &&
		window.__UR_ANALYTICS__.utm_campaign
	) {
		return String(window.__UR_ANALYTICS__.utm_campaign);
	}

	if (
		typeof window.ur_templates_script !== "undefined" &&
		window.ur_templates_script.utm_campaign
	) {
		return String(window.ur_templates_script.utm_campaign);
	}

	if (
		typeof window.ur_form_template_params !== "undefined" &&
		window.ur_form_template_params.utm_campaign
	) {
		return String(window.ur_form_template_params.utm_campaign);
	}

	return "lite-version";
};

/**
 * Lowercase-hyphenate a UTM parameter value.
 *
 * @param {string} value Raw value.
 * @returns {string}
 */
const sanitizeUtmValue = (value) =>
	String(value || "")
		.trim()
		.toLowerCase()
		.replace(/\s+/g, "-");

/**
 * Build a marketing URL with standardized UTM parameters.
 *
 * @param {string} baseUrl Base URL (existing UTM params are overwritten).
 * @param {Object} args
 * @param {string} args.source Required granular location.
 * @param {string} args.medium One of UR_UTM_ALLOWED_MEDIUMS.
 * @param {string} [args.campaign] Defaults to localized campaign.
 * @param {string} args.content Required click target slug.
 * @returns {string}
 */
export const urUtmUrl = (baseUrl, args = {}) => {
	const source = sanitizeUtmValue(args.source || "wp-admin");
	let medium = sanitizeUtmValue(args.medium || "button");

	if (!UR_UTM_ALLOWED_MEDIUMS.includes(medium)) {
		medium = "button";
	}

	const campaign = sanitizeUtmValue(args.campaign || getUrUtmCampaign());
	const content = sanitizeUtmValue(args.content);

	if (
		!content &&
		typeof process !== "undefined" &&
		process.env &&
		process.env.NODE_ENV !== "production"
	) {
		// eslint-disable-next-line no-console
		console.warn(
			"[urUtmUrl] Outbound marketing links must pass a non-empty content argument for utm_content attribution."
		);
	}

	try {
		const url = new URL(baseUrl, window.location.origin);
		url.searchParams.delete("utm_source");
		url.searchParams.delete("utm_medium");
		url.searchParams.delete("utm_campaign");
		url.searchParams.delete("utm_content");
		url.searchParams.set("utm_source", source);
		url.searchParams.set("utm_medium", medium);
		url.searchParams.set("utm_campaign", campaign);
		if (content) {
			url.searchParams.set("utm_content", content);
		}
		return url.toString();
	} catch (e) {
		const params = [
			`utm_source=${encodeURIComponent(source)}`,
			`utm_medium=${encodeURIComponent(medium)}`,
			`utm_campaign=${encodeURIComponent(campaign)}`
		];
		if (content) {
			params.push(`utm_content=${encodeURIComponent(content)}`);
		}
		const join = baseUrl.includes("?") ? "&" : "?";
		return `${baseUrl}${join}${params.join("&")}`;
	}
};
