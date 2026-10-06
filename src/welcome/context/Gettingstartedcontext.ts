export type MembershipSetupType = "membership" | "registration";
export type MembershipPlanType = "free" | "one-time" | "subscription";
export type BillingCycle = "day" | "week" | "month" | "year";

export interface ContentAccess {
	id: string;
	type: "posts" | "pages" | "wholesite";
	value: number[];
}

export interface MembershipPlan {
	id: string;
	name: string;
	type: MembershipPlanType;
	price: string;
	billingCycle: BillingCycle;
	billingCycleCount: string;
	contentAccess: ContentAccess[];
	isNew?: boolean;
}

export interface PaymentSettings {
	currency: string;
	currencySymbol: string;
	offlinePayment: boolean;
	bankDetails: string;
	paypal: boolean;
	paypalMode: string;
	paypalTestEmail: string;
	paypalTestClientId: string;
	paypalTestClientSecret: string;
	paypalProductionEmail: string;
	paypalProductionClientId: string;
	paypalProductionClientSecret: string;
	stripe: boolean;
	stripeTestMode: boolean;
	stripeTestPublishableKey: string;
	stripeTestSecretKey: string;
	stripeLivePublishableKey: string;
	stripeLiveSecretKey: string;
}

export interface GettingStartedState {
	currentStep: number;
	maxCompletedStep: number;
	isLoading: boolean;
	membershipSetupType: MembershipSetupType;
	allowTracking: boolean;
	adminEmail: string;
	membershipPlans: MembershipPlan[];
	paymentSettings: PaymentSettings;
	registrationSettings: {
		loginOption: string;
		defaultRole: string;
	};
}

const generateId = () => Math.random().toString(36).substring(2, 9);

const createDefaultPlan = (
	type: MembershipPlanType = "free"
): MembershipPlan => ({
	id: generateId(),
	name: "",
	type: type,
	price: "",
	billingCycle: "month",
	billingCycleCount: "",
	contentAccess: [],
	isNew: false
});

export const getTotalStepsForType = (
	membershipType: MembershipSetupType
): number => {
	switch (membershipType) {
		case "membership":
			return 4;
		default:
			return 3;
	}
};

export const DEFAULT_WELCOME_STATE = {
	membershipSetupType: "membership" as MembershipSetupType,
	allowTracking: true
};

export const DEFAULT_PAYMENT_SETTINGS: PaymentSettings = {
	currency: "USD",
	currencySymbol: "$",
	offlinePayment: false,
	bankDetails: "",
	paypal: false,
	paypalMode: "test",
	paypalTestEmail: "",
	paypalTestClientId: "",
	paypalTestClientSecret: "",
	paypalProductionEmail: "",
	paypalProductionClientId: "",
	paypalProductionClientSecret: "",
	stripe: false,
	stripeTestMode: false,
	stripeTestPublishableKey: "",
	stripeTestSecretKey: "",
	stripeLivePublishableKey: "",
	stripeLiveSecretKey: ""
};

export const DEFAULT_REGISTRATION_SETTINGS = {
	loginOption: "default",
	defaultRole: "subscriber"
};

export const initialState: GettingStartedState = {
	currentStep: 1,
	maxCompletedStep: 1,
	isLoading: false,
	membershipSetupType: DEFAULT_WELCOME_STATE.membershipSetupType,
	allowTracking: DEFAULT_WELCOME_STATE.allowTracking,
	adminEmail: "",
	membershipPlans: [createDefaultPlan("one-time")],
	paymentSettings: { ...DEFAULT_PAYMENT_SETTINGS },
	registrationSettings: { ...DEFAULT_REGISTRATION_SETTINGS }
};

export type Action =
	| { type: "SET_LOADING"; payload: boolean }
	| { type: "NEXT_STEP" }
	| { type: "PREV_STEP" }
	| { type: "SET_STEP"; payload: number }
	| { type: "SET_MEMBERSHIP_SETUP_TYPE"; payload: MembershipSetupType }
	| { type: "SET_ALLOW_TRACKING"; payload: boolean }
	| { type: "SET_ADMIN_EMAIL"; payload: string }
	| { type: "ADD_MEMBERSHIP_PLAN"; payload?: MembershipPlan }
	| {
			type: "UPDATE_MEMBERSHIP_PLAN";
			payload: { id: string; updates: Partial<MembershipPlan> };
	  }
	| { type: "REMOVE_MEMBERSHIP_PLAN"; payload: string }
	| {
			type: "ADD_CONTENT_ACCESS";
			payload: { planId: string; access: ContentAccess };
	  }
	| {
			type: "SET_PAYMENT_SETTING";
			payload: { key: keyof PaymentSettings; value: boolean | string };
	  }
	| {
			type: "SET_REGISTRATION_SETTINGS";
			payload: { loginOption: string; defaultRole: string };
	  }
	| {
			type: "HYDRATE_FROM_API";
			payload: Partial<GettingStartedState>;
	  }
	| {
			type: "HYDRATE_PAYMENT_SETTINGS";
			payload: Partial<PaymentSettings>;
	  }
	| { type: "RESET_WELCOME_DEFAULTS" }
	| { type: "RESET_MEMBERSHIP_DEFAULTS" }
	| { type: "RESET_PAYMENT_DEFAULTS" }
	| { type: "RESET_SETTINGS_DEFAULTS" };

export const reducer = (
	state: GettingStartedState,
	action: Action
): GettingStartedState => {
	switch (action.type) {
		case "SET_LOADING":
			return { ...state, isLoading: action.payload };

		case "NEXT_STEP":
			const totalSteps = getTotalStepsForType(state.membershipSetupType);
			const nextStep = Math.min(state.currentStep + 1, totalSteps);
			return {
				...state,
				currentStep: nextStep,
				maxCompletedStep: Math.max(state.maxCompletedStep, nextStep)
			};

		case "PREV_STEP":
			return {
				...state,
				currentStep: Math.max(state.currentStep - 1, 1)
			};

		case "SET_STEP":
			return { ...state, currentStep: action.payload };

		case "SET_MEMBERSHIP_SETUP_TYPE":
			const newDefaultType: MembershipPlanType =
				action.payload === "membership" ? "one-time" : "free";

			const updatedPlansOnTypeChange = state.membershipPlans.map(
				(plan, index) => {
					if (index === 0 && plan.name === "") {
						return { ...plan, type: newDefaultType };
					}
					return plan;
				}
			);

			return {
				...state,
				membershipSetupType: action.payload,
				membershipPlans: updatedPlansOnTypeChange
			};

		case "SET_ALLOW_TRACKING":
			return { ...state, allowTracking: action.payload };

		case "SET_ADMIN_EMAIL":
			return { ...state, adminEmail: action.payload };

		case "ADD_MEMBERSHIP_PLAN":
			const defaultTypeForNewPlan: MembershipPlanType =
				state.membershipSetupType === "membership" ? "one-time" : "free";
			const newPlan = action.payload || {
				...createDefaultPlan(defaultTypeForNewPlan),
				isNew: true
			};
			return {
				...state,
				membershipPlans: [...state.membershipPlans, newPlan]
			};

		case "UPDATE_MEMBERSHIP_PLAN":
			return {
				...state,
				membershipPlans: state.membershipPlans.map((plan) =>
					plan.id === action.payload.id
						? { ...plan, ...action.payload.updates }
						: plan
				)
			};

		case "REMOVE_MEMBERSHIP_PLAN":
			return {
				...state,
				membershipPlans: state.membershipPlans.filter(
					(plan) => plan.id !== action.payload
				)
			};

		case "ADD_CONTENT_ACCESS":
			return {
				...state,
				membershipPlans: state.membershipPlans.map((plan) =>
					plan.id === action.payload.planId
						? {
								...plan,
								contentAccess: [
									...plan.contentAccess,
									action.payload.access
								]
						  }
						: plan
				)
			};

		case "SET_PAYMENT_SETTING":
			return {
				...state,
				paymentSettings: {
					...state.paymentSettings,
					[action.payload.key]: action.payload.value
				}
			};

		case "SET_REGISTRATION_SETTINGS":
			return {
				...state,
				registrationSettings: {
					...state.registrationSettings,
					...action.payload
				}
			};

		case "HYDRATE_FROM_API":
			const hydratedStep =
				action.payload.currentStep || state.currentStep;
			const hydratedSetupType =
				action.payload.membershipSetupType || state.membershipSetupType;

			let hydratedPlans = state.membershipPlans;

			if (
				action.payload.membershipPlans &&
				action.payload.membershipPlans.length > 0
			) {
				hydratedPlans = action.payload.membershipPlans;
			} else if (
				state.membershipPlans.length > 0 &&
				state.membershipPlans[0].name === ""
			) {
				const syncedType: MembershipPlanType =
					hydratedSetupType === "membership" ? "one-time" : "free";
				hydratedPlans = state.membershipPlans.map((plan, index) => {
					if (index === 0 && plan.name === "") {
						return { ...plan, type: syncedType };
					}
					return plan;
				});
			}

			return {
				...state,
				...action.payload,
				membershipPlans: hydratedPlans,
				maxCompletedStep: Math.max(
					state.maxCompletedStep,
					hydratedStep,
					action.payload.maxCompletedStep || 1
				)
			};

		case "HYDRATE_PAYMENT_SETTINGS":
			return {
				...state,
				paymentSettings: {
					...state.paymentSettings,
					...action.payload
				}
			};

		case "RESET_WELCOME_DEFAULTS":
			return {
				...state,
				membershipSetupType: DEFAULT_WELCOME_STATE.membershipSetupType,
				allowTracking: DEFAULT_WELCOME_STATE.allowTracking,
				membershipPlans: [createDefaultPlan("one-time")]
			};

		case "RESET_MEMBERSHIP_DEFAULTS":
			const defaultPlanType: MembershipPlanType =
				state.membershipSetupType === "membership" ? "one-time" : "free";
			return {
				...state,
				membershipPlans: [createDefaultPlan(defaultPlanType)]
			};

		case "RESET_PAYMENT_DEFAULTS":
			return {
				...state,
				paymentSettings: { ...DEFAULT_PAYMENT_SETTINGS }
			};

		case "RESET_SETTINGS_DEFAULTS":
			return {
				...state,
				registrationSettings: { ...DEFAULT_REGISTRATION_SETTINGS }
			};

		default:
			return state;
	}
};
