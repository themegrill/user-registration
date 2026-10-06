import {
	Box,
	Container,
	Grid,
	HStack,
	Heading,
	Link,
	Stack,
	Text
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { useCallback, useEffect, useState } from "react";
import { BiBulb } from "react-icons/bi";
import * as URIcon from "../../components/Icon/Icon";

// Import new components
import {
	DefaultFormMissing,
	DisabledEmails,
	LegacyPaymentFields,
	MembershipField,
	PaymentSetup,
	RegistrationDisabled,
	RequiredPagesMissing,
	SendTestEmail,
	SpamProtection
} from "./components";

const PRIMARY_COLOR = "#475bb2";
const docURL = "https://docs.wpuserregistration.com/";
const facebookGroup = "https://www.facebook.com/groups/userregistration";
const featureRequestURL = "https://wpuserregistration.com/feature-requests/";
const submitReviewUrl =
	"https://wordpress.org/support/plugin/user-registration/reviews/?rate=5#new-post";
const ticketUrl =
	"https://wordpress.org/support/plugin/user-registration/#new-topic-0";

const SiteAssistant = () => {
	const [open, setOpen] = useState({
		registrationDisabled: false,
		defaultForm: false,
		requiredPages: false,
		paymentSetup: false,
		disabledEmails: false,
		sendTestEmail: false,
		spamProtection: false,
		membershipField: false,
		legacyPaymentFields: false
	});

	// Check if the WordPress "Anyone can register" option allows registration
	const usersCanRegister =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.users_can_register;

	// Check if default form exists
	const hasDefaultForm =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.has_default_form;

	// Get missing pages data from site_assistant_data
	const missingPagesData =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.missing_pages
			? _UR_DASHBOARD_.site_assistant_data.missing_pages
			: [];

	// Check if the disabled emails notice is already handled (enabled or skipped)
	const initialDisabledEmailsHandled =
		typeof _UR_DASHBOARD_ === "undefined" ||
		!_UR_DASHBOARD_.site_assistant_data ||
		_UR_DASHBOARD_.site_assistant_data.disabled_emails_handled !== false;

	// State to track if the disabled emails notice was handled during this session
	const [disabledEmailsHandled, setDisabledEmailsHandled] = useState(
		initialDisabledEmailsHandled
	);

	// Check if test email was already sent successfully
	const initialTestEmailSent =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.test_email_sent;

	// State to track if test email was sent during this session
	const [testEmailSent, setTestEmailSent] = useState(initialTestEmailSent);

	// Check if spam protection has been handled (configured or skipped)
	const initialSpamProtectionHandled =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.spam_protection_handled;

	// State to track if spam protection was handled during this session
	const [spamProtectionHandled, setSpamProtectionHandled] = useState(
		initialSpamProtectionHandled
	);

	// Check if payment setup is handled (all payments connected or explicitly skipped)
	const paymentConnections =
		(typeof _UR_DASHBOARD_ !== "undefined" &&
			_UR_DASHBOARD_.site_assistant_data &&
			_UR_DASHBOARD_.site_assistant_data.payment_connections) ||
		{};

	const allPaymentsConnected = Object.values(paymentConnections).every(
		(connection) => connection.is_connected
	);
	const isPaymentSetupSkipped =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.payment_setup_handled === true &&
		!allPaymentsConnected;

	const initialPaymentSetupHandled =
		allPaymentsConnected || isPaymentSetupSkipped;

	// State to track if payment setup was handled during this session
	const [paymentSetupHandled, setPaymentSetupHandled] = useState(
		initialPaymentSetupHandled
	);

	// Check if the legacy payment fields notice was already handled (no forms use them, or dismissed)
	const initialLegacyPaymentFieldsHandled =
		typeof _UR_DASHBOARD_ === "undefined" ||
		!_UR_DASHBOARD_.site_assistant_data ||
		_UR_DASHBOARD_.site_assistant_data.legacy_payment_fields_handled !== false;

	// State to track if the legacy payment fields notice was handled during this session
	const [legacyPaymentFieldsHandled, setLegacyPaymentFieldsHandled] = useState(
		initialLegacyPaymentFieldsHandled
	);

	const membershipEnabled =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.membership_enabled;

	const hasMembershipPlans =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.has_membership_plans;

	const initialMembershipFieldHandled =
		typeof _UR_DASHBOARD_ !== "undefined" &&
		_UR_DASHBOARD_.site_assistant_data &&
		_UR_DASHBOARD_.site_assistant_data.membership_field_handled;

	// State to track if membership field was handled during this session
	const [membershipFieldHandled, setMembershipFieldHandled] = useState(
		initialMembershipFieldHandled
	);

	// Determine if we should show the membership field notice
	const shouldShowMembershipField =
		hasDefaultForm &&
		membershipEnabled &&
		hasMembershipPlans &&
		!membershipFieldHandled;

	// State to track if all components are completed
	const [allCompleted, setAllCompleted] = useState(false);

	// Callback to handle when emails are enabled or the notice is skipped
	const handleDisabledEmailsHandled = useCallback(() => {
		setDisabledEmailsHandled(true);
	}, []);

	// Callback to handle when test email is sent successfully
	const handleTestEmailSent = useCallback(() => {
		setTestEmailSent(true);
	}, []);


	// Callback to handle when spam protection is handled (skipped)
	const handleSpamProtectionHandled = useCallback(() => {
		setSpamProtectionHandled(true);
	}, []);

	// Callback to handle when payment setup is handled (any payment connected or skipped)
	const handlePaymentSetupHandled = useCallback(() => {
		setPaymentSetupHandled(true);
	}, []);


	const handleMembershipFieldHandled = useCallback(() => {
		setMembershipFieldHandled(true);
	}, []);

	const handleLegacyPaymentFieldsHandled = useCallback(() => {
		setLegacyPaymentFieldsHandled(true);
	}, []);

	const toggleOpen = useCallback(
		(id) => {
			if (typeof id === "undefined") {
				const site_config_array = [
					usersCanRegister,
					hasDefaultForm,
					missingPagesData.length === 0,
					!shouldShowMembershipField,
					paymentSetupHandled,
					disabledEmailsHandled,
					testEmailSent,
					spamProtectionHandled,
					legacyPaymentFieldsHandled
				];

				const openKeys = [
					"registrationDisabled",
					"defaultForm",
					"requiredPages",
					"membershipField",
					"paymentSetup",
					"disabledEmails",
					"sendTestEmail",
					"spamProtection",
					"legacyPaymentFields"
				];

				const firstFalseIndex = site_config_array.findIndex(
					(item) => item === false
				);

				const firstFalseKey =
					firstFalseIndex !== -1 ? openKeys[firstFalseIndex] : null;
				id = firstFalseKey;
			}

			setOpen((prev) => {
				const newState = Object.keys(prev).reduce((acc, key) => {
					acc[key] = key === id ? !prev[id] : false;
					return acc;
				}, {});
				return newState;
			});
		},
		[
			usersCanRegister,
			hasDefaultForm,
			missingPagesData.length,
			shouldShowMembershipField,
			paymentSetupHandled,
			disabledEmailsHandled,
			testEmailSent,
			spamProtectionHandled,
			legacyPaymentFieldsHandled
		]
	);

	// Check if all components are completed and redirect if so
	useEffect(() => {
		// Check if all components are handled
		const allComponentsHandled =
			usersCanRegister &&
			hasDefaultForm &&
			missingPagesData.length === 0 &&
			!shouldShowMembershipField &&
			disabledEmailsHandled &&
			testEmailSent &&
			spamProtectionHandled &&
			paymentSetupHandled &&
			legacyPaymentFieldsHandled;

		// If all components are handled, show completion message and redirect
		if (allComponentsHandled && !allCompleted) {
			setAllCompleted(true);
			// Small delay to allow user to see the completion message
			setTimeout(() => {
				window.location.href =
					window._UR_DASHBOARD_?.adminURL +
					"admin.php?page=user-registration";
			}, 2000);
		}

		const site_config_array = [
			usersCanRegister,
			hasDefaultForm,
			missingPagesData.length === 0,
			!shouldShowMembershipField,
			disabledEmailsHandled,
			testEmailSent,
			spamProtectionHandled,
			paymentSetupHandled,
			legacyPaymentFieldsHandled
		];

		const site_config_count =
			site_config_array.length - site_config_array.filter(Boolean).length;
		const $ur_menu = document.querySelector(
			"#toplevel_page_user-registration"
		);

		if ($ur_menu) {
			const countElement = $ur_menu.querySelector(
				".wp-submenu .ur-site-config-count"
			);

			if (site_config_count > 0) {
				if (countElement) {
					countElement.textContent = site_config_count;
				} else {
					const newCount = document.createElement("span");
					newCount.classList.add("ur-site-config-count");
					newCount.textContent = site_config_count;
					const submenu = $ur_menu.querySelector(".wp-submenu");
					submenu?.appendChild(newCount);
				}
			} else {
				countElement?.remove();
			}

			if (window.wp?.heartbeat) {
				window.wp.heartbeat.interval("standard");
			}
		}

		toggleOpen();
	}, [
		usersCanRegister,
		hasDefaultForm,
		missingPagesData.length,
		shouldShowMembershipField,
		disabledEmailsHandled,
		testEmailSent,
		spamProtectionHandled,
		paymentSetupHandled,
		legacyPaymentFieldsHandled,
		allCompleted
	]);

	let config_number = 0;

	return (
		<Container maxW="100%" py={1} marginLeft={"10px"}>
			<Grid
				gridGap="5"
				gridTemplateColumns={{
					sm: "1fr",
					md: "2fr 2fr",
					lg: "3fr 2fr",
					xl: "3fr 1fr"
				}}
			>
				{allCompleted && (
					<Box
						p="4"
						bgColor="transparent"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
						textAlign="center"
					>
						<Heading
							as="h3"
							fontSize="md"
							fontWeight="semibold"
							color="green.600"
							mb="1"
						>
							{__("Setup Complete!", "user-registration")}
						</Heading>

						<Text fontSize="xs" color="gray.600">
							{__(
								"Redirecting to All forms...",
								"user-registration"
							)}
						</Text>
					</Box>
				)}
				{!allCompleted && (
					<Stack gap="5">
						{/* Registration Disabled - only show if WordPress "Anyone can register" is off */}
						{!usersCanRegister && (
							<RegistrationDisabled
								isOpen={open.registrationDisabled}
								onToggle={() => toggleOpen("registrationDisabled")}
								numbering={++config_number}
							/>
						)}

						{/* Default Form Missing - only show if no default form exists */}
						{!hasDefaultForm && (
							<DefaultFormMissing
								isOpen={open.defaultForm}
								onToggle={() => toggleOpen("defaultForm")}
								numbering={++config_number}
							/>
						)}

						{/* Required Pages Missing - only show if there are missing pages */}
						{missingPagesData.length > 0 && (
							<RequiredPagesMissing
								isOpen={open.requiredPages}
								onToggle={() => toggleOpen("requiredPages")}
								missingPagesData={missingPagesData}
								numbering={++config_number}
							/>
						)}


						{shouldShowMembershipField && (
							<MembershipField
								isOpen={open.membershipField}
								onToggle={() => toggleOpen("membershipField")}
								onHandled={handleMembershipFieldHandled}
								numbering={++config_number}
							/>
						)}

						{/* Payment Setup - only show if not handled */}
						{!paymentSetupHandled && (
							<PaymentSetup
								isOpen={open.paymentSetup}
								onToggle={() => toggleOpen("paymentSetup")}
								onSkipped={handlePaymentSetupHandled}
								numbering={++config_number}
							/>
						)}

						{/* Legacy Payment Fields - only show while a form still uses one and it hasn't been dismissed */}
						{!legacyPaymentFieldsHandled && (
							<LegacyPaymentFields
								isOpen={open.legacyPaymentFields}
								onToggle={() => toggleOpen("legacyPaymentFields")}
								onSkipped={handleLegacyPaymentFieldsHandled}
								numbering={++config_number}
							/>
						)}

						{/* Emails Disabled - only show while the master disable setting is on */}
						{!disabledEmailsHandled && (
							<DisabledEmails
								isOpen={open.disabledEmails}
								onToggle={() => toggleOpen("disabledEmails")}
								onHandled={handleDisabledEmailsHandled}
								numbering={++config_number}
							/>
						)}

						{/* Send Test Email - only show if test email hasn't been sent successfully */}
						{!testEmailSent && (
							<SendTestEmail
								isOpen={open.sendTestEmail}
								onToggle={() => toggleOpen("sendTestEmail")}
								onEmailSent={handleTestEmailSent}
								numbering={++config_number}
							/>
						)}

						{/* Spam Protection - only show if not handled */}
						{!spamProtectionHandled && (
							<SpamProtection
								isOpen={open.spamProtection}
								onToggle={() => toggleOpen("spamProtection")}
								onSkipped={handleSpamProtectionHandled}
								numbering={++config_number}
							/>
						)}
					</Stack>
				)}
				<Stack gap="5">
					<Stack
						p="6"
						gap="3"
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
					>
						<HStack gap="2">
							<URIcon.Team w="5" h="5" fill={PRIMARY_COLOR} />
							<Heading as="h3" size="sm" fontWeight="semibold">
								{__(
									"User Registration & Membership Community",
									"user-registration"
								)}
							</Heading>
						</HStack>
						<Text fontSize="13px" color="gray.700">
							{__(
								"Join our exclusive group and connect with fellow User Registration & Membership members. Ask questions, contribute to discussions, and share feedback!",
								"user-registration"
							)}
						</Text>
						<Link
							color="var(--chakra-colors-primary-500) !important"
							textDecor="underline"
							href={facebookGroup}
							isExternal
						>
							{__("Join our Facebook Group", "user-registration")}
						</Link>
					</Stack>
					<Stack
						p="6"
						gap="3"
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
					>
						<HStack gap="2">
							<URIcon.DocsLines
								w="5"
								h="5"
								fill={PRIMARY_COLOR}
							/>
							<Heading as="h3" size="sm" fontWeight="semibold">
								{__("Getting Started", "user-registration")}
							</Heading>
						</HStack>
						<Text fontSize="13px" color="gray.700">
							{__(
								"Check our documentation for detailed information on User Registration & Membership features and how to use them.",
								"user-registration"
							)}
						</Text>
						<Link
							color="var(--chakra-colors-primary-500) !important"
							textDecor="underline"
							href={docURL}
							isExternal
						>
							{__("View Documentation", "user-registration")}
						</Link>
					</Stack>
					<Stack
						p="6"
						gap="3"
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
					>
						<HStack gap="2">
							<URIcon.Headphones
								w="5"
								h="5"
								fill={PRIMARY_COLOR}
							/>
							<Heading as="h3" size="sm" fontWeight="semibold">
								{__("Support", "user-registration")}
							</Heading>
						</HStack>
						<Text fontSize="13px" color="gray.700">
							{__(
								"Submit a ticket for encountered issues and get help from our support team instantly.",
								"user-registration"
							)}
						</Text>
						<Link
							color="var(--chakra-colors-primary-500) !important"
							textDecor="underline"
							href={ticketUrl}
							isExternal
						>
							{__("Create a Ticket", "user-registration")}
						</Link>
					</Stack>
					<Stack
						p="6"
						gap="3"
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
					>
						<HStack gap="2">
							<BiBulb size={20} color={PRIMARY_COLOR} />
							<Heading as="h3" size="sm" fontWeight="semibold">
								{__("Feature Request", "user-registration")}
							</Heading>
						</HStack>
						<Text fontSize="13px" color="gray.700">
							{__(
								"Don't find a feature you're looking for? Suggest any features you think would enhance our product.",
								"user-registration"
							)}
						</Text>
						<Link
							color="var(--chakra-colors-primary-500) !important"
							textDecor="underline"
							href={featureRequestURL}
							isExternal
						>
							{__("Request a Feature", "user-registration")}
						</Link>
					</Stack>
					<Stack
						p="6"
						gap="3"
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
					>
						<HStack gap="2">
							<URIcon.Star w="5" h="5" fill={PRIMARY_COLOR} />
							<Heading as="h3" size="sm" fontWeight="semibold">
								{__("Submit a Review", "user-registration")}
							</Heading>
						</HStack>
						<Text fontSize="13px" color="gray.700">
							{__(
								"Please take a moment to give us a review. We appreciate honest feedback that'll help us improve our plugin.",
								"user-registration"
							)}
						</Text>
						<Link
							color="var(--chakra-colors-primary-500) !important"
							textDecor="underline"
							href={submitReviewUrl}
							isExternal
						>
							{__("Submit a Review", "user-registration")}
						</Link>
					</Stack>
					<Stack
						p="6"
						gap="3"
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
					>
						<HStack gap="2">
							<URIcon.Video w="5" h="5" fill={PRIMARY_COLOR} />
							<Heading as="h3" size="sm" fontWeight="semibold">
								{__("Video Tutorials", "user-registration")}
							</Heading>
						</HStack>
						<Text fontSize="13px" color="gray.700">
							{__(
								"Watch our step-by-step video tutorials that'll help you get the best out of User Registration & Membership's features.",
								"user-registration"
							)}
						</Text>
						<Link
							color="var(--chakra-colors-primary-500) !important"
							textDecor="underline"
							isExternal
							href="https://www.youtube.com/@UserRegistration"
						>
							{__("Watch Videos", "user-registration")}
						</Link>
					</Stack>
				</Stack>
			</Grid>
		</Container>
	);
};

export default SiteAssistant;
