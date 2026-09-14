import {
	Box,
	Collapse,
	Flex,
	HStack,
	Heading,
	Icon,
	IconButton,
	Link,
	Stack,
	Text,
	useToast
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { useState } from "react";
import { BiChevronDown, BiChevronUp } from "react-icons/bi";

const LegacyPaymentFields = ({ isOpen, onToggle, onSkipped, numbering }) => {
	const [isSkipping, setIsSkipping] = useState(false);
	const toast = useToast();

	const handleViewForms = () => {
		const adminURL =
			window._UR_DASHBOARD_?.adminURL ||
			`${window.location.origin}/wp-admin/`;
		window.open(`${adminURL}admin.php?page=user-registration`, "_blank");
	};

	const handleSkip = async () => {
		setIsSkipping(true);

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
					action: "user_registration_skip_site_assistant_section",
					section: "legacy_payment_fields",
					security: window._UR_DASHBOARD_?.urRestApiNonce || ""
				})
			});

			const result = await response.json();

			if (result.success) {
				toast({
					title: __("Dismissed", "user-registration"),
					description:
						result.data?.message ||
						__(
							"Legacy payment fields notice dismissed.",
							"user-registration"
						),
					status: "success",
					duration: 3000,
					isClosable: true
				});
				if (onSkipped) {
					onSkipped();
				}
			} else {
				throw new Error(
					result.data?.message || "Failed to dismiss notice"
				);
			}
		} catch (error) {
			toast({
				title: __("Error", "user-registration"),
				description:
					error.message ||
					__(
						"Failed to dismiss notice. Please try again.",
						"user-registration"
					),
				status: "error",
				duration: 3000,
				isClosable: true
			});
		} finally {
			setIsSkipping(false);
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
						__("Legacy Payment Fields In Use", "user-registration")}
				</Heading>
				<IconButton
					aria-label={"legacyPaymentFields"}
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
							"One or more of your forms use payment fields that new forms can no longer add. They keep working here; use the membership field for new payment setups.",
							"user-registration"
						)}
					</Text>

					<Flex
						bg="#f9fafc"
						p="4"
						borderRadius="md"
						justify="space-between"
						align="center"
					>
						<Box>
							<Text
								fontSize={"15px !important"}
								fontWeight="bold"
								mb={1}
							>
								{__("Affected Forms", "user-registration")}
							</Text>
							<Text fontSize="14px" color="gray.600">
								{__(
									"Find which forms use single item, total, multiple choice, subscription plan, quantity, or the payment slider.",
									"user-registration"
								)}
							</Text>
						</Box>
						<Link
							color="primary.500"
							textDecoration="underline"
							onClick={handleViewForms}
							cursor="pointer"
						>
							{__("View Forms", "user-registration")}
						</Link>
					</Flex>

					<HStack justifyContent="flex-end">
						<Link
							fontSize="14px"
							color="gray.500"
							textDecoration="underline"
							onClick={handleSkip}
							cursor="pointer"
							width="fit-content"
							opacity={isSkipping ? 0.6 : 1}
							pointerEvents={isSkipping ? "none" : "auto"}
						>
							{isSkipping
								? __("Dismissing...", "user-registration")
								: __("Got It", "user-registration")}
						</Link>
					</HStack>
				</Stack>
			</Collapse>
		</Stack>
	);
};

export default LegacyPaymentFields;
