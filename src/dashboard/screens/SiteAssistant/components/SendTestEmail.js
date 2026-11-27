import {
	Box,
	Button,
	Collapse,
	Flex,
	FormControl,
	FormLabel,
	Heading,
	HStack,
	Icon,
	IconButton,
	Input,
	Stack,
	Text,
	useToast
} from "@chakra-ui/react";
import { __, sprintf } from "@wordpress/i18n";
import React, { useState } from "react";
import { BiChevronDown, BiChevronUp } from "react-icons/bi";

const SendTestEmail = ({ isOpen, onToggle, onEmailSent, numbering }) => {
	const adminEmail = window._UR_DASHBOARD_?.adminEmail || "test@example.com";
	const emailHealthURL =
		window._UR_DASHBOARD_?.emailHealthURL ||
		`${window._UR_DASHBOARD_?.settingsURL || "admin.php?page=user-registration-settings"}&tab=email&section=email-health`;
	const [email, setEmail] = useState(adminEmail);
	const [sentEmail, setSentEmail] = useState("");
	const [isLoading, setIsLoading] = useState(false);
	const [isConfirming, setIsConfirming] = useState(false);
	const [feedbackState, setFeedbackState] = useState(null); // null | 'sent' | 'not_yet' | 'spam' | 'failed'
	const [sentWhileDisabled, setSentWhileDisabled] = useState(false);
	const [lastError, setLastError] = useState("");
	const toast = useToast();

	const formatErrorWithSuffix = (err, suffix) => {
		const clean = String(err || "").trim();
		if (!clean) {
			return suffix;
		}
		const punctuated = /[.!?]$/.test(clean) ? clean : `${clean}.`;
		return `${punctuated} ${suffix}`;
	};

	const handleSendTestEmail = async () => {
		if (!email || !email.includes("@")) {
			toast({
				title: __("Invalid Email", "user-registration"),
				description: __(
					"Please enter a valid email address.",
					"user-registration"
				),
				status: "error",
				duration: 3000,
				isClosable: true
			});
			return;
		}

		setIsLoading(true);

		try {
			const adminURL =
				window._UR_DASHBOARD_?.adminURL ||
				window.location.origin + "/wp-admin/";
			const response = await fetch(`${adminURL}admin-ajax.php`, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded"
				},
				body: new URLSearchParams({
					action: "user_registration_send_test_email",
					email: email,
					nonce: window._UR_DASHBOARD_?.testEmailNonce || "",
					defer_completion: "1"
				})
			});

			const result = await response.json();

			if (result.success) {
				setSentEmail(email);
				setLastError("");
				setSentWhileDisabled(Boolean(result.data?.emails_disabled));
				setFeedbackState("sent");
			} else {
				const errText =
					result.data?.raw_error ||
					result.data?.message ||
					__(
						"Your server could not send the email.",
						"user-registration"
					);
				setLastError(errText);
				setSentWhileDisabled(false);
				setFeedbackState("failed");
			}
		} catch (error) {
			setLastError(
				error.message ||
					__(
						"Failed to send test email. Please try again.",
						"user-registration"
					)
			);
			setSentWhileDisabled(false);
			setFeedbackState("failed");
		} finally {
			setIsLoading(false);
		}
	};

	const handleConfirmReceived = async () => {
		if (isConfirming) {
			return;
		}
		setIsConfirming(true);
		try {
			const adminURL =
				window._UR_DASHBOARD_?.adminURL ||
				window.location.origin + "/wp-admin/";
			const response = await fetch(`${adminURL}admin-ajax.php`, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded"
				},
				body: new URLSearchParams({
					action: "user_registration_confirm_test_email",
					nonce: window._UR_DASHBOARD_?.testEmailNonce || ""
				})
			});

			if (!response.ok) {
				throw new Error(
					__(
						"Could not confirm test email. Please refresh the page and try again.",
						"user-registration"
					)
				);
			}

			const result = await response.json();
			if (!result || !result.success) {
				throw new Error(
					result?.data?.message ||
						__(
							"Could not confirm test email. Please refresh the page and try again.",
							"user-registration"
						)
				);
			}

			setFeedbackState(null);
			setSentWhileDisabled(false);
			toast({
				title: __("Test Email Confirmed", "user-registration"),
				description: __(
					"Great! Your site is delivering registration emails.",
					"user-registration"
				),
				status: "success",
				duration: 3000,
				isClosable: true
			});

			if (onEmailSent) {
				onEmailSent();
			}
		} catch (error) {
			toast({
				title: __("Confirmation Failed", "user-registration"),
				description:
					error.message ||
					__(
						"Could not confirm test email. Please refresh the page and try again.",
						"user-registration"
					),
				status: "error",
				duration: 4000,
				isClosable: true
			});
		} finally {
			setIsConfirming(false);
		}
	};

	const diagnoseHref = (issue) =>
		`${emailHealthURL}${emailHealthURL.includes("?") ? "&" : "?"}issue=${issue}`;

	return (
		<Stack
			p="6"
			gap="5"
			bgColor="white"
			borderRadius="base"
			border="1px"
			borderColor="gray.100"
		>
			<HStack
				justify={"space-between"}
				onClick={onToggle}
				borderBottom={isOpen && "1px solid #dcdcde"}
				paddingBottom={isOpen && 5}
				_hover={{
					cursor: "pointer"
				}}
			>
				<Heading
					as="h3"
					fontSize="18px"
					fontWeight="semibold"
					lineHeight={"1.2"}
				>
					{numbering +
						") " +
						__("Send Test Email", "user-registration")}
				</Heading>
				<IconButton
					aria-label={"sendTestEmail"}
					icon={
						<Icon
							as={isOpen ? BiChevronUp : BiChevronDown}
							fontSize="2xl"
							fill={isOpen ? "primary.500" : "black"}
						/>
					}
					cursor={"pointer"}
					fontSize={"xl"}
					size="sm"
					boxShadow="none"
					borderRadius="base"
					variant={isOpen ? "solid" : "link"}
					border="none"
				/>
			</HStack>
			<Collapse in={isOpen}>
				<Stack gap={5}>
					<Text fontWeight={"light"} fontSize={"15px !important"}>
						{__(
							"Make sure emails are being sent to your users during registration. Test by sending a sample email to yourself.",
							"user-registration"
						)}
					</Text>
					<FormControl>
						<FormLabel
							fontSize={"15px !important"}
							fontWeight="medium"
						>
							{__(
								"Email Address (To send test email to)",
								"user-registration"
							)}
						</FormLabel>
						<Input
							type="email"
							value={email}
							onChange={(e) => setEmail(e.target.value)}
							placeholder={adminEmail}
							borderColor="gray.300"
							fontSize={"15px !important"}
							_focus={{
								borderColor: "primary.500",
								boxShadow:
									"0 0 0 1px var(--chakra-colors-primary-500)"
							}}
						/>
					</FormControl>
					<Button
						colorScheme={"primary"}
						rounded="base"
						width={"fit-content"}
						fontSize="14px"
						onClick={handleSendTestEmail}
						isLoading={isLoading}
						loadingText={__("Sending...", "user-registration")}
					>
						{__("Send Test Email", "user-registration")}
					</Button>

					<Box aria-live="polite">
						{feedbackState === "sent" && (
							<Stack spacing={2}>
								<Flex
									p="14px 16px"
									bg="gray.50"
									border="1px solid"
									borderColor="gray.200"
									borderRadius="6px"
									align="center"
									justify="space-between"
									gap="3"
									wrap="wrap"
									role="status"
								>
									<Text fontSize="14px" color="gray.700" m="0">
										{sprintf(
											/* translators: %s: recipient email address */
											__(
												"Test email sent to %s (it can take a minute). Did you receive it?",
												"user-registration"
											),
											sentEmail
										)}
									</Text>
									<HStack spacing={2} wrap="wrap">
										<Button
											size="sm"
											variant="outline"
											bg="white"
											borderColor="gray.300"
											color="gray.800"
											fontWeight="medium"
											isLoading={isConfirming}
											onClick={handleConfirmReceived}
										>
											{__(
												"Yes, received",
												"user-registration"
											)}
										</Button>
										<Button
											size="sm"
											variant="outline"
											bg="white"
											borderColor="gray.300"
											color="gray.800"
											fontWeight="medium"
											isDisabled={isConfirming}
											onClick={() =>
												setFeedbackState("not_yet")
											}
										>
											{__("Not yet", "user-registration")}
										</Button>
										<Button
											size="sm"
											variant="outline"
											bg="white"
											borderColor="gray.300"
											color="gray.800"
											fontWeight="medium"
											isDisabled={isConfirming}
											onClick={() =>
												setFeedbackState("spam")
											}
										>
											{__("In spam", "user-registration")}
										</Button>
									</HStack>
								</Flex>
								{sentWhileDisabled && (
									<Flex
										p="10px 14px"
										bg="#fffbeb"
										border="1px solid"
										borderColor="#fde68a"
										borderRadius="6px"
										align="center"
										justify="space-between"
										gap="3"
										wrap="wrap"
										role="status"
									>
										<Text
											fontSize="13px"
											color="gray.700"
											m="0"
										>
											{__(
												'Note: "Disable emails" is currently turned on in General settings, so registration emails are still paused.',
												"user-registration"
											)}
										</Text>
										<Button
											as="a"
											href={emailHealthURL}
											size="xs"
											variant="link"
											colorScheme="primary"
										>
											{__(
												"Check Email Health →",
												"user-registration"
											)}
										</Button>
									</Flex>
								)}
							</Stack>
						)}

						{feedbackState === "not_yet" && (
							<Flex
								p="14px 16px"
								bg="#fffbeb"
								border="1px solid"
								borderColor="#fde68a"
								borderRadius="6px"
								align="center"
								justify="space-between"
								gap="3"
								wrap="wrap"
								role="status"
							>
								<Box flex="1" minW="220px">
									<Text
										fontWeight="semibold"
										fontSize="14px"
										color="gray.900"
										mb="2px"
									>
										{__(
											"Didn't receive the email?",
											"user-registration"
										)}
									</Text>
									<Text
										fontSize="13px"
										color="gray.600"
										m="0"
									>
										{__(
											"Delivery can take a minute. If it still hasn't arrived, this is usually caused by server mail settings — not your form. We can check your setup together.",
											"user-registration"
										)}
									</Text>
								</Box>
								<Button
									as="a"
									href={diagnoseHref("not_received")}
									colorScheme="primary"
									size="sm"
									rounded="base"
									fontSize="13.5px"
								>
									{__(
										"Let's Diagnose →",
										"user-registration"
									)}
								</Button>
							</Flex>
						)}

						{feedbackState === "spam" && (
							<Flex
								p="14px 16px"
								bg="#fffbeb"
								border="1px solid"
								borderColor="#fde68a"
								borderRadius="6px"
								align="center"
								justify="space-between"
								gap="3"
								wrap="wrap"
								role="status"
							>
								<Box flex="1" minW="220px">
									<Text
										fontWeight="semibold"
										fontSize="14px"
										color="gray.900"
										mb="2px"
									>
										{__(
											"Found it in Spam?",
											"user-registration"
										)}
									</Text>
									<Text
										fontSize="13px"
										color="gray.600"
										m="0"
									>
										{__(
											"Emails landing in Spam are usually caused by server mail authentication or sender settings. We can check your setup together.",
											"user-registration"
										)}
									</Text>
								</Box>
								<Button
									as="a"
									href={diagnoseHref("spam")}
									colorScheme="primary"
									size="sm"
									rounded="base"
									fontSize="13.5px"
								>
									{__(
										"Let's Diagnose →",
										"user-registration"
									)}
								</Button>
							</Flex>
						)}

						{feedbackState === "failed" && (
							<Flex
								p="14px 16px"
								bg="#fef2f2"
								border="1px solid"
								borderColor="#fecaca"
								borderRadius="6px"
								align="center"
								justify="space-between"
								gap="3"
								wrap="wrap"
								role="alert"
							>
								<Box flex="1" minW="220px">
									<Text
										fontWeight="semibold"
										fontSize="14px"
										color="#991b1b"
										mb="2px"
									>
										{__(
											"Couldn't send test email",
											"user-registration"
										)}
									</Text>
									<Text
										fontSize="13px"
										color="gray.600"
										m="0"
									>
										{formatErrorWithSuffix(
											lastError,
											__(
												"We can check what's blocking delivery.",
												"user-registration"
											)
										)}
									</Text>
								</Box>
								<Button
									as="a"
									href={diagnoseHref("failed")}
									colorScheme="primary"
									size="sm"
									rounded="base"
									fontSize="13.5px"
								>
									{__(
										"Let's Diagnose →",
										"user-registration"
									)}
								</Button>
							</Flex>
						)}
					</Box>
				</Stack>
			</Collapse>
		</Stack>
	);
};

export default SendTestEmail;
