import {
	Badge,
	Box,
	Button,
	Collapse,
	Flex,
	FormControl,
	FormLabel,
	HStack,
	Heading,
	Icon,
	IconButton,
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
	const formExists = forms.some(
		(form) => form.id === Number(siteAssistantData.default_form_id)
	);
	const initialFormId = formExists
		? siteAssistantData.default_form_id
		: forms.length > 0
			? forms[0].id
			: "";

	const [selectedFormId, setSelectedFormId] = useState(initialFormId);

	const defaultFormTitle =
		forms.find((form) => form.id === Number(selectedFormId))?.title ||
		(forms.length > 0 ? forms[0].title : "");

	const handleMigrate = async () => {
		if (!selectedFormId) {
			toast({
				title: __("Selection Required", "user-registration"),
				description: __(
					"Please select a registration form to link users to.",
					"user-registration"
				),
				status: "warning",
				duration: 5000,
				isClosable: true
			});
			return;
		}

		setIsMigrating(true);

		try {
			const adminURL =
				window._UR_DASHBOARD_?.adminURL ||
				`${window.location.origin}/wp-admin/`;

			let totalLinked = 0;
			let hasMore = true;

			while (hasMore) {
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

				if (!response.ok) {
					throw new Error(
						sprintf(
							/* translators: %d: HTTP status code */
							__("Request failed with status %d.", "user-registration"),
							response.status
						)
					);
				}

				let result;
				try {
					result = await response.json();
				} catch {
					throw new Error(
						__(
							"Received an invalid response from the server.",
							"user-registration"
						)
					);
				}

				if (!result.success) {
					throw new Error(
						result.data?.message ||
							__("Failed to link users.", "user-registration")
					);
				}

				const count = result.data?.count || 0;
				totalLinked += count;
				hasMore = Boolean(result.data?.has_more);

				// Guard against potential infinite loop if no accounts could be linked in a batch.
				if (hasMore && count === 0) {
					break;
				}
			}

			toast({
				title: __("Users Linked", "user-registration"),
				description: sprintf(
					/* translators: %d: number of users linked */
					_n(
						"%d user successfully linked to registration form.",
						"%d users successfully linked to registration form.",
						totalLinked,
						"user-registration"
					),
					totalLinked
				),
				status: "success",
				duration: 5000,
				isClosable: true
			});

			if (onMigrated) {
				onMigrated();
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
				duration: 5000,
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

			if (!response.ok) {
				throw new Error(
					sprintf(
						/* translators: %d: HTTP status code */
						__("Request failed with status %d.", "user-registration"),
						response.status
					)
				);
			}

			let result;
			try {
				result = await response.json();
			} catch {
				throw new Error(
					__(
						"Received an invalid response from the server.",
						"user-registration"
					)
				);
			}

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
					duration: 5000,
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
				duration: 5000,
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
				borderBottom={isOpen ? "1px solid" : undefined}
				borderColor={isOpen ? "gray.200" : undefined}
				paddingBottom={isOpen ? 5 : undefined}
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
					<Badge
						colorScheme="orange"
						variant="subtle"
						borderRadius="full"
						px={2.5}
						py={0.5}
						fontSize="xs"
						fontWeight="semibold"
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
					</Badge>
				</HStack>
				<IconButton
					aria-label={__(
						"Toggle link existing users section",
						"user-registration"
					)}
					aria-expanded={isOpen}
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
								"We detected %d existing user account created outside User Registration (such as administrators or WooCommerce customers). Link it to a registration form so this user can view and update profile details on your frontend account page.",
								"We detected %d existing user accounts created outside User Registration (such as administrators or WooCommerce customers). Link them to a registration form so they can view and update profile details on your frontend account page.",
								unlinkedCount,
								"user-registration"
							),
							unlinkedCount
						)}
					</Text>

					{forms.length > 1 ? (
						<Box
							bg="gray.50"
							p="4"
							borderRadius="md"
							border="1px"
							borderColor="gray.200"
						>
							<FormControl maxW="400px">
								<FormLabel
									htmlFor="ur-migrate-form-select"
									fontSize="14px"
									fontWeight="bold"
									color="gray.800"
									mb={1}
								>
									{__(
										"Select Registration Form",
										"user-registration"
									)}
								</FormLabel>
								<Text fontSize="13px" color="gray.600" mb={3}>
									{__(
										"Choose which form fields will be available when these users edit their profile:",
										"user-registration"
									)}
								</Text>
								<Select
									id="ur-migrate-form-select"
									aria-label={__(
										"Select Registration Form",
										"user-registration"
									)}
									value={selectedFormId}
									onChange={(e) =>
										setSelectedFormId(e.target.value)
									}
									bg="white"
									size="sm"
									borderRadius="base"
								>
									{forms.map((form) => (
										<option key={form.id} value={form.id}>
											{form.title}
										</option>
									))}
								</Select>
							</FormControl>
						</Box>
					) : (
						<Flex
							bg="gray.50"
							p="4"
							borderRadius="md"
							border="1px"
							borderColor="gray.200"
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
							"Existing passwords, user roles, and account data remain completely unchanged. This association links form fields and cannot be undone automatically.",
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
							isDisabled={isMigrating || isSkipping}
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

						<Button
							variant="link"
							fontSize="14px"
							fontWeight="normal"
							color="gray.500"
							textDecoration="underline"
							onClick={handleSkip}
							cursor="pointer"
							width="fit-content"
							isLoading={isSkipping}
							isDisabled={isMigrating || isSkipping}
							loadingText={__("Skipping...", "user-registration")}
						>
							{__("Skip this step", "user-registration")}
						</Button>
					</HStack>
				</Stack>
			</Collapse>
		</Stack>
	);
};

export default MigrateExistingUsers;
