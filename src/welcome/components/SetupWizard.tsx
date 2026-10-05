import { ArrowBackIcon, ArrowForwardIcon } from "@chakra-ui/icons";
import { Box, Button, Flex, Link, useColorModeValue } from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import React, { useEffect, useMemo } from "react";
import {
	apiGet,
	apiPost,
	mapApiToSetupType,
	mapPaymentSettingsToApi,
	mapSetupToApiType,
	mapSetupToInterest
} from "../api/gettingStartedApi";
import {
	MembershipPlan,
	MembershipSetupType
} from "../context/Gettingstartedcontext";
import { useStateValue } from "../context/StateProvider";
import Stepper from "./Stepper";
import FinishStep from "./steps/FinishStep";
import MembershipStep from "./steps/MembershipStep";
import PaymentStep from "./steps/PaymentStep";
import SettingsStep from "./steps/SettingsStep";
import WelcomeStep from "./steps/WelcomeStep";

interface StepConfig {
	id: string;
	label: string;
	stepNumber: number;
}

const ALL_STEPS: StepConfig[] = [
	{ id: "welcome", label: "Welcome", stepNumber: 1 },
	{ id: "membership", label: "Membership", stepNumber: 2 },
	{ id: "payment", label: "Payment", stepNumber: 3 },
	{ id: "settings", label: "Settings", stepNumber: 4 },
	{ id: "finish", label: "Finish", stepNumber: 5 }
];

const getVisibleSteps = (
	membershipType: MembershipSetupType,
	hasPaidPlan: boolean
): StepConfig[] => {
	let filteredSteps: StepConfig[];

	switch (membershipType) {
		case "membership":
			if (hasPaidPlan) {
				filteredSteps = ALL_STEPS.filter(
					(step) => step.id !== "settings"
				);
			} else {
				filteredSteps = ALL_STEPS.filter(
					(step) => step.id !== "payment" && step.id !== "settings"
				);
			}
			break;
		default:
			filteredSteps = ALL_STEPS.filter(
				(step) => step.id !== "membership" && step.id !== "payment"
			);
	}

	return filteredSteps.map((step, index) => ({
		...step,
		stepNumber: index + 1
	}));
};

const getStepIdByDisplayNumber = (
	displayNumber: number,
	visibleSteps: StepConfig[]
): string => {
	const step = visibleSteps.find((s) => s.stepNumber === displayNumber);
	return step ? step.id : "welcome";
};

const HEADER_HEIGHT = "65px";

const SetupWizard: React.FC = () => {
	const { state, dispatch } = useStateValue();
	const { currentStep, isLoading, membershipSetupType, membershipPlans } =
		state;

	const cardBg = useColorModeValue("white", "gray.800");
	const textColor = useColorModeValue("gray.800", "white");
	const mutedColor = useColorModeValue("gray.600", "gray.400");
	const pageBg = useColorModeValue("#F8F8FA", "gray.900");

	const hasPaidPlan = membershipPlans.some(
		(plan) => plan.type === "one-time" || plan.type === "subscription"
	);

	const visibleSteps = useMemo(
		() => getVisibleSteps(membershipSetupType, hasPaidPlan),
		[membershipSetupType, hasPaidPlan]
	);

	const totalSteps = visibleSteps.length;

	useEffect(() => {
		if (currentStep > totalSteps) {
			dispatch({ type: "SET_STEP", payload: totalSteps });
		}
	}, [currentStep, totalSteps, dispatch]);

	const currentStepId = getStepIdByDisplayNumber(currentStep, visibleSteps);

	const isFinishStep = currentStepId === "finish";

	useEffect(() => {
		const loadInitial = async () => {
			try {
				dispatch({ type: "SET_LOADING", payload: true });
				
				dispatch({ type: "RESET_MEMBERSHIP_DEFAULTS" });
				
				const wizard: any = await apiGet("");
				const welcome: any = await apiGet("/welcome").catch(() => null);

				dispatch({
					type: "HYDRATE_FROM_API",
					payload: {
						currentStep: wizard.current_step || 1,
						membershipSetupType: wizard.membership_type
							? mapApiToSetupType(
									wizard.membership_type,
									welcome?.membership_interest
							  )
							: state.membershipSetupType,
						allowTracking:
							typeof welcome?.allow_usage_tracking === "boolean"
								? welcome.allow_usage_tracking
								: true,
						adminEmail:
							typeof welcome?.admin_email === "string" &&
							welcome.admin_email
								? welcome.admin_email
								: state.adminEmail,
					}
				});
			} catch (e) {
				console.error(e);
			} finally {
				dispatch({ type: "SET_LOADING", payload: false });
			}
		};

		loadInitial();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	const mapPlanToApi = (plan: MembershipPlan) => {
		return {
			id: plan.id,
			name: plan.name,
			type: plan.type,
			price: plan.price,
			billing_cycle: plan.billingCycle,
			billing_cycle_count: plan.billingCycleCount,
			access: plan.contentAccess.map((a) => ({
				type: a.type,
				value: a.value
			}))
		};
	};

	const handleNext = async () => {
		try {
			dispatch({ type: "SET_LOADING", payload: true });

			if (currentStepId === "welcome") {
				await apiPost("/welcome", {
					membership_type: mapSetupToApiType(
						state.membershipSetupType
					),
					membership_interest: mapSetupToInterest(
						state.membershipSetupType
					),
					allow_usage_tracking: state.allowTracking,
					admin_email: state.adminEmail
				});
			} else if (currentStepId === "membership") {
				await apiPost("/memberships", {
					memberships: state.membershipPlans.map(mapPlanToApi)
				});

				if (!hasPaidPlan) {
					await apiPost("/finish");
				}
			} else if (currentStepId === "payment") {
				await apiPost(
					"/payments",
					mapPaymentSettingsToApi(state.paymentSettings)
				);
				await apiPost("/finish");
			} else if (currentStepId === "settings") {
				await apiPost("/settings", {
					login_option: state.registrationSettings.loginOption,
					default_role: state.registrationSettings.defaultRole
				});
			}

			if (currentStep < totalSteps) {
				dispatch({ type: "SET_STEP", payload: currentStep + 1 });
			}
		} catch (e) {
			console.error(e);
		} finally {
			dispatch({ type: "SET_LOADING", payload: false });
		}
	};

	const handleBack = () => {
		if (currentStep > 1) {
			dispatch({ type: "SET_STEP", payload: currentStep - 1 });
		}
	};

	const handleSkip = async () => {
		try {
			await apiPost("/skip", {
				step: currentStep,
				step_id: currentStepId
			});

			if (currentStep < totalSteps) {
				dispatch({ type: "SET_STEP", payload: currentStep + 1 });
			}
		} catch (e) {
			console.error(e);
		}
	};

	const handleStepClick = async (stepNumber: number) => {
		if (stepNumber === currentStep) {
			return;
		}

		if (stepNumber < currentStep) {
			dispatch({ type: "SET_STEP", payload: stepNumber });
			return;
		}

		if (stepNumber === currentStep + 1) {
			await handleSkip();
			return;
		}
	};

	const handleClose = () => {
		const dashboardUrl =
			(window as any).urmSetupWizard?.dashboardUrl ||
			"/wp-admin/admin.php?page=user-registration-dashboard";
		window.location.href = dashboardUrl;
	};

	const renderStep = () => {
		switch (currentStepId) {
			case "welcome":
				return <WelcomeStep />;
			case "membership":
				return <MembershipStep />;
			case "payment":
				return <PaymentStep />;
			case "settings":
				return <SettingsStep />;
			case "finish":
				return <FinishStep />;
			default:
				return <WelcomeStep />;
		}
	};

	return (
		<Box minH="100vh" bg={pageBg}>
			<Stepper
				steps={visibleSteps}
				currentStep={currentStep}
				onStepClick={handleStepClick}
				onClose={handleClose}
			/>

			<Box pt={HEADER_HEIGHT}>
				<Flex
					justify="center"
					align="flex-start"
					px={{ base: 3, md: 4 }}
					py={{ base: 2, md: 3 }}
				>
					<Box
						w="100%"
						maxW="920px"
						bg={cardBg}
						borderWidth="1px"
						borderColor="#F4F4F4"
						borderRadius="8px"
						px={{ base: 4, md: 8 }}
						py={{ base: 5, md: 6 }}
						boxShadow="0 10px 15px -3px rgba(0, 0, 0, 0.06)"
					>
						<Box mb={isFinishStep ? 0 : 6}>{renderStep()}</Box>
						{!isFinishStep && (
							<Flex
								justify="space-between"
								align="center"
								flexDir={{ base: "column-reverse", sm: "row" }}
								gap={{ base: 4, sm: 0 }}
							>
								{currentStep > 1 ? (
									<Link
										display="flex"
										alignItems="center"
										fontSize="sm"
										color={mutedColor}
										_hover={{
											color: textColor,
											textDecoration: "none"
										}}
										cursor="pointer"
										onClick={handleBack}
									>
										<ArrowBackIcon mr={1} />
										{__("Back", "user-registration")}
									</Link>
								) : (
									<Box />
								)}

								<Flex gap={{ base: 3, md: 4 }} align="center">
									<Link
										fontSize="sm"
										color="#999999"
										_hover={{
											color: "#475BB2",
											textDecoration: "underline"
										}}
										cursor="pointer"
										onClick={handleSkip}
										display={{ base: "none", sm: "block" }}
									>
										{__(
											"Skip this step",
											"user-registration"
										)}
									</Link>
									<Button
										bg="#475BB2"
										color="white"
										rightIcon={<ArrowForwardIcon />}
										_hover={{ bg: "#38488e" }}
										_active={{ bg: "#475BB2" }}
										onClick={handleNext}
										isLoading={isLoading}
										px={{ base: 2, md: 4 }}
										py={2}
										borderRadius="4px"
										fontSize={{ base: "sm", md: "md" }}
									>
										{__("Next", "user-registration")}
									</Button>
								</Flex>
							</Flex>
						)}
					</Box>
				</Flex>
			</Box>
		</Box>
	);
};

export default SetupWizard;
