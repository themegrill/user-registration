import { __ } from '@wordpress/i18n';

const { isPro } = typeof _UR_DASHBOARD_ !== 'undefined' && _UR_DASHBOARD_;

// Check if Site Assistant should be shown based on backend data
const shouldShowSiteAssistant =
	typeof _UR_DASHBOARD_ !== 'undefined' && _UR_DASHBOARD_.site_assistant_data
		? // Check if any options are not handled
			!_UR_DASHBOARD_.site_assistant_data.has_default_form ||
			_UR_DASHBOARD_.site_assistant_data.missing_pages.length > 0 ||
			!_UR_DASHBOARD_.site_assistant_data.test_email_sent ||
			!_UR_DASHBOARD_.site_assistant_data.wordpress_login_handled ||
			!_UR_DASHBOARD_.site_assistant_data.spam_protection_handled ||
			!_UR_DASHBOARD_.site_assistant_data.payment_setup_handled ||
			!_UR_DASHBOARD_.site_assistant_data.legacy_payment_fields_handled
		: true; // Default to true if data not available

let ROUTES = [
	// Always include Site Assistant route, but conditionally show it
	{
		route: '/dashboard',
		label: __('Site Assistant', 'user-registration'),
		hidden: !shouldShowSiteAssistant, // Add hidden property for conditional visibility
	},
	{
		route: '/features',
		label: __('All Features', 'user-registration'),
	},
	{
		route: '/settings',
		label: __('Settings', 'user-registration'),
	},

	{
		route: '/help',
		label: __('Help', 'user-registration'),
	},
	// {
	// 	route: "/products",
	// 	label: __("Other Products", "user-registration"),
	// },
];

// if (!isPro) {
// 	// Find the index where to insert Free vs Pro (after Settings)
// 	const settingsIndex = ROUTES.findIndex(route => route.route === "/settings");
// 	if (settingsIndex !== -1) {
// 		ROUTES.splice(settingsIndex + 1, 0, {
// 			route: "/free-vs-pro",
// 			label: __("Free vs Pro", "user-registration"),
// 		});
// 	}
// }
export default ROUTES;

export const CHANGELOG_TAG_COLORS = {
	fix: {
		color: 'primary.500',
		bgColor: 'primary.100',
		scheme: 'primary',
	},
	feature: {
		color: 'green.500',
		bgColor: 'green.50',
		scheme: 'green',
	},
	new: {
		color: 'green.500',
		bgColor: 'green.50',
		scheme: 'green',
	},
	enhance: {
		color: 'teal.500',
		bgColor: 'teal.50',
		scheme: 'teal',
	},
	refactor: {
		color: 'pink.500',
		bgColor: 'pink.50',
		scheme: 'pink',
	},
	security: {
		color: 'pink.500',
		bgColor: 'pink.50',
		scheme: 'pink',
	},
	dev: {
		color: 'orange.500',
		bgColor: 'orange.50',
		scheme: 'orange',
	},
	tweak: {
		color: 'purple.500',
		bgColor: 'purple.50',
		scheme: 'purple',
	},
};
