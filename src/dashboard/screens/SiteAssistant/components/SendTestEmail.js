import {
	Button,
	Collapse,
	HStack,
	Heading,
	Icon,
	IconButton,
	Stack,
	Text,
	Input,
	FormControl,
	FormLabel,
	useToast
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import React, { useState, useEffect } from "react";
import { BiChevronDown, BiChevronUp } from "react-icons/bi";

const SendTestEmail = ({ isOpen, onToggle, onEmailSent, numbering }) => {
	const adminEmail = window._UR_DASHBOARD_?.adminEmail || "test@example.com";
	const [email, setEmail] = useState(adminEmail);
	const [isLoading, setIsLoading] = useState(false);
	const toast = useToast();

	// Add CSS styles for toast anchor links
	useEffect(() => {
		const styleId = "ur-toast-anchor-styles";
		if (!document.getElementById(styleId)) {
			const style = document.createElement("style");
			style.id = styleId;
			style.textContent = `
				.ur-toast-error-content a {
					color: white !important;
					text-decoration: underline !important;
				}
				.ur-toast-error-content a:hover {
					color: #ffd700 !important;
					text-decoration: underline !important;
				}
			`;
			document.head.appendChild(style);
		}
	}, []);

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
				window.location.origin + "/wp-admin";
			const response = await fetch(`${adminURL}admin-ajax.php`, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded"
				},
				body: new URLSearchParams({
					action: "user_registration_send_test_email",
					email: email,
					nonce: window._UR_DASHBOARD_?.testEmailNonce || ""
				})
			});

			const result = await response.json();

			if (result.success) {
				toast({
					title: __("Test Email Sent", "user-registration"),
					description:
						result.data?.message ||
						__(
							"Test email has been sent successfully.",
							"user-registration"
						),
					status: "success",
					duration: 3000,
					isClosable: true
				});
				// Notify parent component to hide this section
				if (onEmailSent) {
					onEmailSent();
				}
			} else {
				throw new Error(
					result.data.message ||
						__(
							"Test email has was unsuccessfully.",
							"user-registration"
						)
				);
			}
		} catch (error) {
			// Check if error message contains HTML
			const errorMessage =
				error.message ||
				__(
					"Failed to send test email. Please try again.",
					"user-registration"
				);
			const hasHtml = /<[^>]*>/g.test(errorMessage);

			toast({
				title: __("Error", "user-registration"),
				description: hasHtml ? (
					<div
						dangerouslySetInnerHTML={{ __html: errorMessage }}
						className="ur-toast-error-content"
					/>
				) : (
					errorMessage
				),
				status: "error",
				duration: 3000,
				isClosable: true
			});
		} finally {
			setIsLoading(false);
		}
	};

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
					<HStack gap={3}>
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
						<Button
							as="a"
							href={`${
								window._UR_DASHBOARD_?.adminURL ||
								window.location.origin + "/wp-admin/"
							}admin.php?page=user-registration-settings&tab=email&section=health-checkup`}
							variant="outline"
							colorScheme={"primary"}
							rounded="base"
							width={"fit-content"}
							fontSize="14px"
						>
							{__("Health Checkup", "user-registration")}
						</Button>
					</HStack>
				</Stack>
			</Collapse>
		</Stack>
	);
};

export default SendTestEmail;
