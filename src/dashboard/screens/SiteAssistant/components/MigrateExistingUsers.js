import {
	Box,
	Button,
	Collapse,
	Flex,
	HStack,
	Heading,
	Icon,
	IconButton,
	Link,
	Select,
	Stack,
	Text,
	useToast
} from "@chakra-ui/react";
import { __, _n, sprintf } from "@wordpress/i18n";
import React, { useState } from "react";
import { BiChevronDown, BiChevronUp } from "react-icons/bi";

const MigrateExistingUsers = ({
	isOpen,
	onToggle,
	onMigrated,
	onSkipped,
	numbering
}) => {
	const toast = useToast();
	const [isMigrating, setIsMigrating] = useState(false);
	const [isSkipping, setIsSkipping] = useState(false);

	const siteAssistantData = window._UR_DASHBOARD_?.site_assistant_data || {};
	const unlinkedCount = siteAssistantData.unlinked_users_count || 0;
	const forms = siteAssistantData.registration_forms || [];
	const initialFormId =
		siteAssistantData.default_form_id ||
		(forms.length > 0 ? forms[0].id : "");

	const [selectedFormId, setSelectedFormId] = useState(initialFormId);

	const defaultFormTitle =
		forms.find((form) => form.id === Number(selectedFormId))?.title ||
		(forms.length > 0
			? forms[0].title
			: __("Default Registration Form", "user-registration"));

	const handleMigrate = async () => {
		if (!selectedFormId) {
			toast({
				title: __("Selection Required", "user-registration"),
				description: __(
					"Please select a registration form to link users to.",
					"user-registration"
				),
				status: "warning",
				duration: 3000,
				isClosable: true
			});
			return;
		}

		setIsMigrating(true);

		try {
			const adminURL =
				window._UR_DASHBOARD_?.adminURL ||
				`${window.location.origin}/wp-admin/`;
			const response = await fetch(`${adminURL}admin-ajax.php`, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded"
				},
				body: new URLSearchParams({
					action: "user_registration_migrate_existing_users",
					form_id: selectedFormId,
					security: window._UR_DASHBOARD_?.urRestApiNonce || ""
				})
			});

			const result = await response.json();

			if (result.success) {
				toast({
					title: __("Users Linked", "user-registration"),
					description:
						result.data?.message ||
						__(
							"Existing users have been successfully linked to the registration form.",
							"user-registration"
						),
					status: "success",
					duration: 3000,
					isClosable: true
				});

				if (onMigrated) {
					onMigrated();
				}
			} else {
				throw new Error(
					result.data?.message ||
						__("Failed to link users.", "user-registration")
				);
			}
		} catch (error) {
			toast({
				title: __("Error", "user-registration"),
				description:
					error.message ||
					__(
						"Failed to link users. Please try again.",
						"user-registration"
					),
				status: "error",
				duration: 3000,
				isClosable: true
			});
		} finally {
			setIsMigrating(false);
		}
	};

	const handleSkip = async () => {
		setIsSkipping(true);

		try {
			const adminURL =
				window._UR_DASHBOARD_?.adminURL ||
				`${window.location.origin}/wp-admin/`;
			const response = await fetch(`${adminURL}admin-ajax.php`, {
				method: "POST",
				headers: {
					"Content-Type": "application/x-www-form-urlencoded"
				},
				body: new URLSearchParams({
					action: "user_registration_skip_site_assistant_section",
					section: "migrate_users",
					security: window._UR_DASHBOARD_?.urRestApiNonce || ""
				})
			});

			const result = await response.json();

			if (result.success) {
				toast({
					title: __("Skipped", "user-registration"),
					description:
						result.data?.message ||
						__(
							"Linking existing users step has been skipped.",
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
					result.data?.message ||
						__("Failed to skip step.", "user-registration")
				);
			}
		} catch (error) {
			toast({
				title: __("Error", "user-registration"),
				description:
					error.message ||
					__(
						"Failed to skip step. Please try again.",
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
				<HStack spacing={3}>
					<Heading
						as="h3"
						fontSize="18px"
						fontWeight="semibold"
						lineHeight={"1.2"}
					>
						{numbering +
							") " +
							__("Link Existing Users", "user-registration")}
					</Heading>
					<Box
						px={2.5}
						py={0.5}
						borderRadius="full"
						bgColor="orange.50"
						border="1px"
						borderColor="orange.200"
						fontSize="xs"
						fontWeight="semibold"
						color="orange.800"
					>
						{sprintf(
							/* translators: %d: number of unlinked users */
							_n(
								"%d unlinked",
								"%d unlinked",
								unlinkedCount,
								"user-registration"
							),
							unlinkedCount
						)}
					</Box>
				</HStack>
				<IconButton
					aria-label={"migrateUsers"}
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
						{sprintf(
							/* translators: %d: number of unlinked users */
							_n(
								"We detected %d existing user account created outside User Registration. Link it to a registration form so this user can view and update their profile details on your frontend account page.",
								"We detected %d existing user accounts created outside User Registration. Link them to a registration form so they can view and update their profile details on your frontend account page.",
								unlinkedCount,
								"user-registration"
							),
							unlinkedCount
						)}
					</Text>

					{forms.length > 1 ? (
						<Box
							bg="#f9fafc"
							p="4"
							borderRadius="md"
							border="1px"
							borderColor="gray.200"
						>
							<Text
								fontSize="14px"
								fontWeight="bold"
								color="gray.800"
								mb={1}
							>
								{__(
									"Select Registration Form",
									"user-registration"
								)}
							</Text>
							<Text fontSize="13px" color="gray.600" mb={3}>
								{__(
									"Choose which form fields will be available when these users edit their profile:",
									"user-registration"
								)}
							</Text>
							<Select
								value={selectedFormId}
								onChange={(e) =>
									setSelectedFormId(e.target.value)
								}
								bg="white"
								size="sm"
								borderRadius="base"
								maxW="400px"
							>
								{forms.map((form) => (
									<option key={form.id} value={form.id}>
										{form.title} (ID: {form.id})
									</option>
								))}
							</Select>
						</Box>
					) : (
						<Flex
							bg="#f9fafc"
							p="4"
							borderRadius="md"
							border="1px"
							borderColor="gray.200"
							justify="space-between"
							align="center"
						>
							<Box>
								<Text fontSize="14px" color="gray.700" mb={0.5}>
									{__(
										"Associated Form:",
										"user-registration"
									)}{" "}
									<Text
										as="span"
										fontWeight="bold"
										color="gray.800"
									>
										{defaultFormTitle}
									</Text>
								</Text>
								<Text fontSize="13px" color="gray.600">
									{__(
										"All existing accounts will be linked to this form's profile fields.",
										"user-registration"
									)}
								</Text>
							</Box>
						</Flex>
					)}

					<Text fontSize="12px" color="gray.500">
						{__(
							"Existing passwords, user roles, and account data remain completely unchanged. No notification emails will be sent.",
							"user-registration"
						)}
					</Text>

					<HStack spacing={4} alignItems="center" pt={2}>
						<Button
							colorScheme={"primary"}
							rounded="base"
							width={"fit-content"}
							onClick={handleMigrate}
							py={5}
							size={"sm"}
							fontSize="14px"
							isLoading={isMigrating}
							loadingText={__("Linking...", "user-registration")}
						>
							{sprintf(
								/* translators: %d: number of unlinked users */
								_n(
									"Link %d User",
									"Link %d Users",
									unlinkedCount,
									"user-registration"
								),
								unlinkedCount
							)}
						</Button>

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
								? __("Skipping...", "user-registration")
								: __("Skip this step", "user-registration")}
						</Link>
					</HStack>
				</Stack>
			</Collapse>
		</Stack>
	);
};

export default MigrateExistingUsers;
