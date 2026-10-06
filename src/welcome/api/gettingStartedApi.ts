import {
	MembershipPlanType,
	MembershipSetupType,
	PaymentSettings
} from "../context/Gettingstartedcontext";

const REST_ROOT =
	(window as any)._UR_WIZARD_?.restURL ||
	`${window.location.origin}/wp-json/`;

export const API_BASE = `${REST_ROOT}user-registration/v1/getting-started`;

export interface CurrencyData {
	code: string;
	name: string;
	symbol: string;
}

export interface PaymentSettingsResponse {
	payment_gateways: any[];
	currencies: CurrencyData[];
	currency: string;
}

const getHeaders = () => {
	const nonce =
		(window as any)._UR_WIZARD_?.urRestApiNonce ||
		(window as any).urmSetupWizard?.nonce ||
		(window as any).wpApiSettings?.nonce;

	return {
		"Content-Type": "application/json",
		...(nonce ? { "X-WP-Nonce": nonce } : {})
	};
};


export const apiGet = async <T = any>(path: string): Promise<T> => {
	const res = await fetch(`${API_BASE}${path}`, {
		method: "GET",
		headers: getHeaders(),
		credentials: "same-origin"
	});
	const json = await res.json();
	if (!json.success) {
		throw new Error(json.message || "Request failed");
	}
	return json.data ?? json;
};

export const apiPost = async <T = any>(
	path: string,
	body?: any
): Promise<T> => {
	const res = await fetch(`${API_BASE}${path}`, {
		method: "POST",
		headers: getHeaders(),
		credentials: "same-origin",
		body: body ? JSON.stringify(body) : undefined
	});
	const json = await res.json();
	if (!json.success) {
		throw new Error(json.message || "Request failed");
	}
	return json.data ?? json;
};

export const mapSetupToApiType = (type: MembershipSetupType): string => {
	return type === "membership" ? "paid_membership" : "normal";
};

export const mapSetupToInterest = (type: MembershipSetupType): string => {
	return type === "membership" ? "yes" : "no";
};

export const mapApiToSetupType = (apiType: string): MembershipSetupType => {
	switch (apiType) {
		case "paid_membership":
		case "free_membership":
			return "membership";
		default:
			return "registration";
	}
};

export const mapPlanTypeToApi = (type: MembershipPlanType): string => {
	switch (type) {
		case "free":
			return "free";
		case "one-time":
			return "paid";
		case "subscription":
			return "subscription";
		default:
			return "free";
	}
};

export const mapApiToPlanType = (apiType: string): MembershipPlanType => {
	switch (apiType) {
		case "free":
			return "free";
		case "paid":
			return "one-time";
		case "subscription":
			return "subscription";
		default:
			return "free";
	}
};

export const mapPaymentSettingsToApi = (settings: PaymentSettings) => {
	const isTestMode = settings.paypalMode === "test";
	return {
		currency: settings.currency,
		offline_payment: settings.offlinePayment,
		bank_details: settings.bankDetails,
		paypal: settings.paypal,
		paypal_mode: settings.paypalMode || "test",
		paypal_email: isTestMode ? settings.paypalTestEmail : settings.paypalProductionEmail,
		paypal_client_id: isTestMode ? settings.paypalTestClientId : settings.paypalProductionClientId,
		paypal_client_secret: isTestMode ? settings.paypalTestClientSecret : settings.paypalProductionClientSecret,
		paypal_test_email: settings.paypalTestEmail,
		paypal_test_client_id: settings.paypalTestClientId,
		paypal_test_client_secret: settings.paypalTestClientSecret,
		paypal_production_email: settings.paypalProductionEmail,
		paypal_production_client_id: settings.paypalProductionClientId,
		paypal_production_client_secret: settings.paypalProductionClientSecret,
		stripe: settings.stripe,
		stripe_test_mode: settings.stripeTestMode,
		stripe_test_publishable_key: settings.stripeTestPublishableKey,
		stripe_test_secret_key: settings.stripeTestSecretKey,
		stripe_live_publishable_key: settings.stripeLivePublishableKey,
		stripe_live_secret_key: settings.stripeLiveSecretKey
	};
};

export const mapApiToPaymentSettings = (
	apiData: any
): Partial<PaymentSettings> => {
	return {
		currency: apiData.currency ?? "USD",
		currencySymbol: apiData.currency_symbol ?? "$",
		offlinePayment: apiData.offline_payment ?? false,
		bankDetails: apiData.bank_details ?? "",
		paypal: apiData.paypal ?? false,
		paypalMode: apiData.paypal_mode ?? "test",
		paypalTestEmail: apiData.paypal_test_email ?? "",
		paypalTestClientId: apiData.paypal_test_client_id ?? "",
		paypalTestClientSecret: apiData.paypal_test_client_secret ?? "",
		paypalProductionEmail: apiData.paypal_production_email ?? "",
		paypalProductionClientId: apiData.paypal_production_client_id ?? "",
		paypalProductionClientSecret: apiData.paypal_production_client_secret ?? "",
		stripe: apiData.stripe ?? false,
		stripeTestMode: apiData.stripe_test_mode ?? false,
		stripeTestPublishableKey: apiData.stripe_test_publishable_key ?? "",
		stripeTestSecretKey: apiData.stripe_test_secret_key ?? "",
		stripeLivePublishableKey: apiData.stripe_live_publishable_key ?? "",
		stripeLiveSecretKey: apiData.stripe_live_secret_key ?? ""
	};
};

export const mapBillingCycleToApi = (cycle: string): string => {
	return cycle;
};
export const mapApiToBillingCycle = (apiCycle: string): string => {
	const cycleMap: Record<string, string> = {
		daily: "day",
		weekly: "week",
		monthly: "month",
		yearly: "year",
		day: "day",
		week: "week",
		month: "month",
		year: "year"
	};
	return cycleMap[apiCycle] || "month";
};
