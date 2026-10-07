import {
	Box,
	Collapse,
	Flex,
	Heading,
	HStack,
	Icon,
	IconButton,
	Input,
	InputGroup,
	InputProps,
	InputRightElement,
	Popover,
	PopoverArrow,
	PopoverBody,
	PopoverContent,
	PopoverTrigger,
	Select,
	Skeleton,
	Switch,
	Text,
	Textarea,
	useColorModeValue,
	VStack
} from "@chakra-ui/react";
import { ViewIcon, ViewOffIcon } from "@chakra-ui/icons";
import { __ } from "@wordpress/i18n";
import React, { useEffect, useState } from "react";
import {
	apiGet,
	CurrencyData,
	PaymentSettingsResponse
} from "../../api/gettingStartedApi";
import { PaymentSettings } from "../../context/Gettingstartedcontext";
import { useStateValue } from "../../context/StateProvider";

interface PaymentGatewayData {
	id: string;
	label: string;
	description: string;
	enabled: boolean;
	configured: boolean;
	settings_url: string;
	paypal_mode?: string;
	paypal_test_email?: string;
	paypal_test_client_id?: string;
	paypal_test_client_secret?: string;
	paypal_production_email?: string;
	paypal_production_client_id?: string;
	paypal_production_client_secret?: string;
	is_new_installation?: boolean;
	bank_details?: string;
	stripe_test_mode?: boolean;
	stripe_test_publishable_key?: string;
	stripe_test_secret_key?: string;
	stripe_live_publishable_key?: string;
	stripe_live_secret_key?: string;
}

const InfoIcon: React.FC = () => (
	<Icon viewBox="0 0 16 16" boxSize="14px" color="#383838">
		<circle
			cx="8"
			cy="8"
			r="7"
			stroke="currentColor"
			strokeWidth="1.5"
			fill="none"
		/>
		<path
			fill="currentColor"
			d="M8 7a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 018 7zM8 4.5a.75.75 0 100 1.5.75.75 0 000-1.5z"
		/>
	</Icon>
);

interface CustomTooltipProps {
	label: string;
	children?: React.ReactNode;
}

const CustomTooltip: React.FC<CustomTooltipProps> = ({ label }) => {
	return (
		<Popover trigger="hover" placement="bottom-start" gutter={8}>
			<PopoverTrigger>
				<Box
					as="span"
					cursor="pointer"
					display="inline-flex"
					alignItems="center"
				>
					<InfoIcon />
				</Box>
			</PopoverTrigger>
			<PopoverContent
				bg="white"
				border="none"
				boxShadow="0px 4px 16px rgba(0, 0, 0, 0.12)"
				borderRadius="4px"
				width="auto"
				maxW="250px"
				_focus={{ boxShadow: "0px 4px 16px rgba(0, 0, 0, 0.12)" }}
			>
				<PopoverArrow bg="white" boxShadow="none" />
				<PopoverBody
					px={3}
					py={2}
					fontSize="13px"
					color="#222222"
					fontWeight="400"
					lineHeight="1.5"
				>
					{label}
				</PopoverBody>
			</PopoverContent>
		</Popover>
	);
};

// Consistent width for all labels
const LABEL_WIDTH = "200px";
// Nested labels are smaller to compensate for border offset (ml={1} + pl={4} = ~20px)
const NESTED_LABEL_WIDTH = "180px";
const CONTENT_FLEX = "1";

interface PaymentOptionProps {
	label: string;
	isChecked: boolean;
	onChange: (checked: boolean) => void;
	children?: React.ReactNode;
}

const PaymentOption: React.FC<PaymentOptionProps> = ({
	label,
	isChecked,
	onChange,
	children
}) => {
	const textColor = useColorModeValue("gray.800", "white");

	return (
		<Box>
			<Flex align="center" py={4}>
				<Text
					fontWeight="500"
					color={textColor}
					fontSize="sm"
					w={LABEL_WIDTH}
					flexShrink={0}
				>
					{label}
				</Text>
				<Box flex={CONTENT_FLEX}>
					<Switch
						isChecked={isChecked}
						onChange={(e) => onChange(e.target.checked)}
						colorScheme="blue"
						size="sm"
						sx={{
							"& .chakra-switch__track[data-checked]": {
								bg: "#475BB2"
							}
						}}
					/>
				</Box>
			</Flex>
			<Collapse in={isChecked && !!children} animateOpacity>
				<Box
					pb={4}
					ml={1}
					pl={4}
					borderLeftWidth="1px"
					borderLeftColor="gray.300"
				>
					{children}
				</Box>
			</Collapse>
		</Box>
	);
};

interface FieldRowProps {
	label: string;
	tooltip?: string;
	children: React.ReactNode;
	alignItems?: string;
	isNested?: boolean;
	isRequired?: boolean;
}

const FieldRow: React.FC<FieldRowProps> = ({
	label,
	tooltip,
	children,
	alignItems = "center",
	isNested = true,
	isRequired = false
}) => {
	const mutedColor = useColorModeValue("#383838", "gray.300");
	const labelW = isNested ? NESTED_LABEL_WIDTH : LABEL_WIDTH;

	return (
		<Flex align={alignItems} py={2}>
			<HStack spacing={1} w={labelW} flexShrink={0}>
				<Text fontSize="sm" color={mutedColor}>
					{label}
				</Text>
				{isRequired && (
					<Text as="span" color="red.500" fontSize="sm">
						*
					</Text>
				)}
				{tooltip && <CustomTooltip label={tooltip} />}
			</HStack>
			<Box flex={CONTENT_FLEX}>{children}</Box>
		</Flex>
	);
};

/**
 * Password-masked input with an eye button that reveals or hides the value.
 */
const SecretInput: React.FC<InputProps> = (props) => {
	const [isVisible, setIsVisible] = useState(false);

	return (
		<InputGroup>
			<Input {...props} type={isVisible ? "text" : "password"} pr={10} />
			<InputRightElement>
				<IconButton
					variant="ghost"
					size="sm"
					aria-label={
						isVisible
							? __("Hide secret", "user-registration")
							: __("Show secret", "user-registration")
					}
					aria-pressed={isVisible}
					icon={isVisible ? <ViewOffIcon /> : <ViewIcon />}
					onClick={() => setIsVisible((visible) => !visible)}
				/>
			</InputRightElement>
		</InputGroup>
	);
};

const PaymentStep: React.FC = () => {
	const { state, dispatch } = useStateValue();
	const { paymentSettings } = state;
	const [isLoadingSettings, setIsLoadingSettings] = useState(false);
	const [currencies, setCurrencies] = useState<CurrencyData[]>([]);
	const [isNewInstallation, setIsNewInstallation] = useState(true);

	const textColor = useColorModeValue("gray.800", "white");
	const subtextColor = useColorModeValue("gray.600", "gray.300");
	const inputBg = useColorModeValue("white", "gray.700");
	const inputBorder = useColorModeValue("gray.300", "gray.600");

	useEffect(() => {
		const loadPaymentSettings = async () => {
			try {
				setIsLoadingSettings(true);
				const response =
					await apiGet<PaymentSettingsResponse>("/payments");

				if (response.currencies) {
					setCurrencies(response.currencies);
				}

				if (response.currency) {
					dispatch({
						type: "SET_PAYMENT_SETTING",
						payload: {
							key: "currency",
							value: response.currency
						}
					});
				}

				if (response.payment_gateways) {
					const gateways =
						response.payment_gateways as PaymentGatewayData[];

					gateways.forEach((gateway) => {
						if (gateway.id === "offline_payment") {
							dispatch({
								type: "SET_PAYMENT_SETTING",
								payload: {
									key: "offlinePayment",
									value: gateway.enabled
								}
							});
							if (gateway.bank_details) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "bankDetails",
										value: gateway.bank_details
									}
								});
							}
						} else if (gateway.id === "paypal") {
							dispatch({
								type: "SET_PAYMENT_SETTING",
								payload: {
									key: "paypal",
									value: gateway.enabled
								}
							});
							if (gateway.paypal_mode) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalMode",
										value: gateway.paypal_mode
									}
								});
							}
							if (gateway.paypal_test_email) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalTestEmail",
										value: gateway.paypal_test_email
									}
								});
							}
							if (gateway.paypal_test_client_id) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalTestClientId",
										value: gateway.paypal_test_client_id
									}
								});
							}
							if (gateway.paypal_test_client_secret) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalTestClientSecret",
										value: gateway.paypal_test_client_secret
									}
								});
							}
							if (gateway.paypal_production_email) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalProductionEmail",
										value: gateway.paypal_production_email
									}
								});
							}
							if (gateway.paypal_production_client_id) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalProductionClientId",
										value: gateway.paypal_production_client_id
									}
								});
							}
							if (gateway.paypal_production_client_secret) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "paypalProductionClientSecret",
										value: gateway.paypal_production_client_secret
									}
								});
							}
							if (typeof gateway.is_new_installation === "boolean") {
								setIsNewInstallation(gateway.is_new_installation);
							}
						} else if (gateway.id === "stripe") {
							dispatch({
								type: "SET_PAYMENT_SETTING",
								payload: {
									key: "stripe",
									value: gateway.enabled
								}
							});
							if (typeof gateway.stripe_test_mode === "boolean") {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "stripeTestMode",
										value: gateway.stripe_test_mode
									}
								});
							}
							if (gateway.stripe_test_publishable_key) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "stripeTestPublishableKey",
										value: gateway.stripe_test_publishable_key
									}
								});
							}
							if (gateway.stripe_test_secret_key) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "stripeTestSecretKey",
										value: gateway.stripe_test_secret_key
									}
								});
							}
							if (gateway.stripe_live_publishable_key) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "stripeLivePublishableKey",
										value: gateway.stripe_live_publishable_key
									}
								});
							}
							if (gateway.stripe_live_secret_key) {
								dispatch({
									type: "SET_PAYMENT_SETTING",
									payload: {
										key: "stripeLiveSecretKey",
										value: gateway.stripe_live_secret_key
									}
								});
							}
						}
					});
				}
			} catch (e) {
				console.error("Failed to load payment settings:", e);
			} finally {
				setIsLoadingSettings(false);
			}
		};

		loadPaymentSettings();
	}, [dispatch]);

	const handlePaymentSettingChange = (
		key: keyof PaymentSettings,
		value: boolean | string
	) => {
		dispatch({
			type: "SET_PAYMENT_SETTING",
			payload: { key, value }
		});
	};

	const inputStyles = {
		bg: inputBg,
		borderColor: inputBorder,
		fontSize: "sm",
		borderRadius: "4px",
		_focus: {
			borderColor: "#475BB2",
			boxShadow: "0 0 0 1px #475BB2"
		}
	};

	return (
		<>
			<Heading
				size="lg"
				fontFamily="Inter"
				fontWeight={600}
				fontSize="21px"
				lineHeight="34px"
				letterSpacing="-0.01em"
				color={textColor}
				mb={2}
			>
				{__("Payments", "user-registration")}
			</Heading>

			<Text fontSize="sm" color={subtextColor} mb={8}>
				{__(
					"Set up payment options for your membership plans. Choose currency and enable payment gateways. You can edit this anytime.",
					"user-registration"
				)}
			</Text>

			{/* Currency Row */}
			<Flex align="center" mb={2} py={2}>
				<Text
					fontWeight="500"
					color={textColor}
					fontSize="sm"
					w={LABEL_WIDTH}
					flexShrink={0}
				>
					{__("Currency", "user-registration")}
				</Text>
				<Box flex={CONTENT_FLEX}>
					{isLoadingSettings ? (
						<Skeleton
							height="40px"
							width="100%"
							borderRadius="4px"
						/>
					) : (
						<Select
							value={paymentSettings.currency || ""}
							onChange={(e) =>
								handlePaymentSettingChange(
									"currency",
									e.target.value
								)
							}
							{...inputStyles}
							placeholder={__(
								"Select currency",
								"user-registration"
							)}
						>
							{currencies.map((currency) => (
								<option
									key={currency.code}
									value={currency.code}
								>
									{currency.name} ({currency.symbol})
								</option>
							))}
						</Select>
					)}
				</Box>
			</Flex>

			<VStack spacing={0} align="stretch">
				{/* Offline Payment */}
				<PaymentOption
					label={__("Offline Payment", "user-registration")}
					isChecked={paymentSettings.offlinePayment}
					onChange={(checked) =>
						handlePaymentSettingChange("offlinePayment", checked)
					}
				>
					<FieldRow
						label={__("Bank Details", "user-registration")}
						tooltip={__(
							"Enter your bank account details for offline payments",
							"user-registration"
						)}
						alignItems="flex-start"
					>
						<Textarea
							value={paymentSettings.bankDetails || ""}
							onChange={(e) =>
								handlePaymentSettingChange(
									"bankDetails",
									e.target.value
								)
							}
							{...inputStyles}
							rows={3}
						/>
					</FieldRow>
				</PaymentOption>

				{/* PayPal */}
				<PaymentOption
					label={__("PayPal", "user-registration")}
					isChecked={paymentSettings.paypal}
					onChange={(checked) =>
						handlePaymentSettingChange("paypal", checked)
					}
				>
					<VStack spacing={0} align="stretch">
						<FieldRow
							label={__("Mode", "user-registration")}
						>
							<Select
								value={paymentSettings.paypalMode || "test"}
								onChange={(e) =>
									handlePaymentSettingChange(
										"paypalMode",
										e.target.value
									)
								}
								{...inputStyles}
							>
								<option value="production">
									{__("Production", "user-registration")}
								</option>
								<option value="test">
									{__("Test/Sandbox", "user-registration")}
								</option>
							</Select>
						</FieldRow>

						{paymentSettings.paypalMode === "test" ? (
							<>
								{!isNewInstallation && paymentSettings.paypalTestEmail && (
									<FieldRow
										label={__("PayPal Email", "user-registration")}
										tooltip={__(
											"Enter the email address associated with your PayPal account",
											"user-registration"
										)}
									>
										<Input
											type="email"
											value={paymentSettings.paypalTestEmail || ""}
											onChange={(e) =>
												handlePaymentSettingChange(
													"paypalTestEmail",
													e.target.value
												)
											}
											{...inputStyles}
										/>
									</FieldRow>
								)}

								<FieldRow
									label={__("Client ID", "user-registration")}
									tooltip={__(
										"Your client_id, Required for subscription related operations.",
										"user-registration"
									)}
								>
									<Input
										type="text"
										value={paymentSettings.paypalTestClientId || ""}
										onChange={(e) =>
											handlePaymentSettingChange(
												"paypalTestClientId",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>

								<FieldRow
									label={__("Client Secret", "user-registration")}
									tooltip={__(
										"Your client_secret, Required for subscription related operations",
										"user-registration"
									)}
								>
									<SecretInput
										key="paypalTestClientSecret"
										value={paymentSettings.paypalTestClientSecret || ""}
										onChange={(e) =>
											handlePaymentSettingChange(
												"paypalTestClientSecret",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>
							</>
						) : (
							<>
								{!isNewInstallation && paymentSettings.paypalProductionEmail && (
									<FieldRow
										label={__("PayPal Email", "user-registration")}
										tooltip={__(
											"Enter the email address associated with your PayPal account",
											"user-registration"
										)}
									>
										<Input
											type="email"
											value={paymentSettings.paypalProductionEmail || ""}
											onChange={(e) =>
												handlePaymentSettingChange(
													"paypalProductionEmail",
													e.target.value
												)
											}
											{...inputStyles}
										/>
									</FieldRow>
								)}

								<FieldRow
									label={__("Client ID", "user-registration")}
									tooltip={__(
										"Your client_id, Required for subscription related operations.",
										"user-registration"
									)}
								>
									<Input
										type="text"
										value={paymentSettings.paypalProductionClientId || ""}
										onChange={(e) =>
											handlePaymentSettingChange(
												"paypalProductionClientId",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>

								<FieldRow
									label={__("Client Secret", "user-registration")}
									tooltip={__(
										"Your client_secret, Required for subscription related operations",
										"user-registration"
									)}
								>
									<SecretInput
										key="paypalProductionClientSecret"
										value={paymentSettings.paypalProductionClientSecret || ""}
										onChange={(e) =>
											handlePaymentSettingChange(
												"paypalProductionClientSecret",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>
							</>
						)}
					</VStack>
				</PaymentOption>

				{/* Stripe */}
				<PaymentOption
					label={__("Stripe", "user-registration")}
					isChecked={paymentSettings.stripe}
					onChange={(checked) =>
						handlePaymentSettingChange("stripe", checked)
					}
				>
					<VStack spacing={0} align="stretch">
						<FieldRow
							label={__("Enable Test Mode", "user-registration")}
							tooltip={__(
								"Enable test mode to use Stripe's test environment",
								"user-registration"
							)}
						>
							<Switch
								isChecked={
									paymentSettings.stripeTestMode || false
								}
								onChange={(e) =>
									handlePaymentSettingChange(
										"stripeTestMode",
										e.target.checked
									)
								}
								colorScheme="blue"
								size="sm"
								sx={{
									"& .chakra-switch__track[data-checked]": {
										bg: "#475BB2"
									}
								}}
							/>
						</FieldRow>

						{paymentSettings.stripeTestMode ? (
							<>
								<FieldRow
									label={__(
										"Test Publishable Key",
										"user-registration"
									)}
									tooltip={__(
										"Your Stripe test publishable key (starts with pk_test_)",
										"user-registration"
									)}
								>
									<Input
										type="text"
										value={
											paymentSettings.stripeTestPublishableKey ||
											""
										}
										onChange={(e) =>
											handlePaymentSettingChange(
												"stripeTestPublishableKey",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>

								<FieldRow
									label={__(
										"Test Secret Key",
										"user-registration"
									)}
									tooltip={__(
										"Your Stripe test secret key (starts with sk_test_)",
										"user-registration"
									)}
								>
									<SecretInput
										key="stripeTestSecretKey"
										value={
											paymentSettings.stripeTestSecretKey ||
											""
										}
										onChange={(e) =>
											handlePaymentSettingChange(
												"stripeTestSecretKey",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>
							</>
						) : (
							<>
								<FieldRow
									label={__(
										"Live Publishable Key",
										"user-registration"
									)}
									tooltip={__(
										"Your Stripe live publishable key (starts with pk_live_)",
										"user-registration"
									)}
								>
									<Input
										type="text"
										value={
											paymentSettings.stripeLivePublishableKey ||
											""
										}
										onChange={(e) =>
											handlePaymentSettingChange(
												"stripeLivePublishableKey",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>

								<FieldRow
									label={__(
										"Live Secret Key",
										"user-registration"
									)}
									tooltip={__(
										"Your Stripe live secret key (starts with sk_live_)",
										"user-registration"
									)}
								>
									<SecretInput
										key="stripeLiveSecretKey"
										value={
											paymentSettings.stripeLiveSecretKey ||
											""
										}
										onChange={(e) =>
											handlePaymentSettingChange(
												"stripeLiveSecretKey",
												e.target.value
											)
										}
										{...inputStyles}
									/>
								</FieldRow>
							</>
						)}
					</VStack>
				</PaymentOption>
			</VStack>
		</>
	);
};

export default PaymentStep;
