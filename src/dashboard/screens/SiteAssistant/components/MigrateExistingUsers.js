import {
	Avatar,
	AvatarGroup,
	Badge,
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
	StackDivider,
	Text,
	useToast
} from "@chakra-ui/react";
import { createInterpolateElement } from "@wordpress/element";
import { __, _n, sprintf } from "@wordpress/i18n";
import React, { useState } from "react";
import { BiChevronDown, BiChevronUp } from "react-icons/bi";

/**
 * Format a count with the site locale's digit grouping, e.g. 1250 → "1,250".
 *
 * @param {number} count Number to format.
 * @return {string} Formatted number.
 */
const formatCount = (count) => {
	try {
		return new Intl.NumberFormat(
			document.documentElement.lang || undefined
		).format(count);
	} catch {
		return String(count);
	}
};

/**
 * Build a short, readable list of unlinked user names, e.g. "Ann, Bob and 3 more".
 *
 * @param {Array<{name: string}>} users Preview users, newest first.
 * @param {number} total Total number of unlinked users.
 * @return {string} Names sentence.
 */
const getPreviewNamesText = (users, total) => {
	const [first, second] = users.map((user) => user.name);

	if (total <= 1) {
		return first;
	}

	// The count is cached briefly, so the live preview can hold fewer names than it.
	if (!second) {
		return sprintf(
			/* translators: 1: user name, 2: number of other users */
			_n(
				"%1$s and %2$s more",
				"%1$s and %2$s more",
				total - 1,
				"user-registration"
			),
			first,
			formatCount(total - 1)
		);
	}

	if (total === 2) {
		/* translators: 1: first user name, 2: second user name */
		return sprintf(__("%1$s and %2$s", "user-registration"), first, second);
	}

	return sprintf(
		/* translators: 1: first user name, 2: second user name, 3: number of other users */
		_n(
			"%1$s, %2$s and %3$s more",
			"%1$s, %2$s and %3$s more",
			total - 2,
			"user-registration"
		),
		first,
		second,
		formatCount(total - 2)
	);
};

/**
 * Site Assistant step that links users created outside User Registration to a registration form.
 *
 * @param {Object} props Component props.
 * @param {boolean} props.isOpen Whether the card body is expanded.
 * @param {Function} props.onToggle Toggles the card body.
 * @param {Function} props.onMigrated Called once the users are linked (or none were left to link).
 * @param {Function} props.onSkipped Called once the step is skipped.
 * @param {number} props.numbering Position of this step in the checklist.
 * @return {JSX.Element} The step card.
 */
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
	const [linkedSoFar, setLinkedSoFar] = useState(0);

	const siteAssistantData = window._UR_DASHBOARD_?.site_assistant_data || {};
	const unlinkedCount = siteAssistantData.unlinked_users_count || 0;
	const forms = siteAssistantData.registration_forms || [];
	const previewUsers = siteAssistantData.unlinked_users_preview || [];
	const formExists = forms.some(
		(form) => form.id === Number(siteAssistantData.default_form_id)
	);
	const initialFormId = formExists
		? siteAssistantData.default_form_id
		: forms.length > 0
			? forms[0].id
			: "";

	const [selectedFormId, setSelectedFormId] = useState(initialFormId);

	const selectedFormTitle =
		forms.find((form) => form.id === Number(selectedFormId))?.title ||
		(forms.length > 0 ? forms[0].title : "");

	const handleMigrate = async () => {
		if (!selectedFormId) {
			toast({
				title: __("Selection required", "user-registration"),
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
		setLinkedSoFar(0);

		try {
			const adminURL =
				window._UR_DASHBOARD_?.adminURL ||
				`${window.location.origin}/wp-admin/`;

			let totalLinked = 0;
			let hasMore = true;
			let isStalled = false;

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
							__(
								"Request failed with status %d.",
								"user-registration"
							),
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
				setLinkedSoFar(totalLinked);
				hasMore = Boolean(result.data?.has_more);

				// Guard against potential infinite loop if no accounts could be linked in a batch.
				if (hasMore && count === 0) {
					isStalled = true;
					break;
				}
			}

			if (isStalled) {
				toast({
					title: __("Linking incomplete", "user-registration"),
					description:
						totalLinked > 0
							? sprintf(
									/* translators: %s: number of users linked */
									_n(
										"Linked %s user, but some accounts couldn't be linked. Please try again.",
										"Linked %s users, but some accounts couldn't be linked. Please try again.",
										totalLinked,
										"user-registration"
									),
									formatCount(totalLinked)
								)
							: __(
									"No users could be linked. Please try again.",
									"user-registration"
								),
					status: "warning",
					duration: 5000,
					isClosable: true
				});
				return;
			}

			// Another admin may have linked everyone since this page loaded.
			if (totalLinked === 0) {
				toast({
					title: __("Nothing to link", "user-registration"),
					description: __(
						"These users are already linked to a registration form.",
						"user-registration"
					),
					status: "info",
					duration: 5000,
					isClosable: true
				});

				if (onMigrated) {
					onMigrated();
				}
				return;
			}

			toast({
				title: _n(
					"User linked",
					"Users linked",
					totalLinked,
					"user-registration"
				),
				description: sprintf(
					/* translators: 1: number of users linked, 2: registration form title */
					_n(
						"%1$s user linked to “%2$s”.",
						"%1$s users linked to “%2$s”.",
						totalLinked,
						"user-registration"
					),
					formatCount(totalLinked),
					selectedFormTitle
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
				title: __("Couldn't link users", "user-registration"),
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
						__(
							"Request failed with status %d.",
							"user-registration"
						),
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
							"Step skipped. It will come back if more users need linking.",
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
				title: __("Couldn't skip this step", "user-registration"),
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
							/* translators: %s: number of unlinked users */
							_n(
								"%s unlinked",
								"%s unlinked",
								unlinkedCount,
								"user-registration"
							),
							formatCount(unlinkedCount)
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
						{createInterpolateElement(
							sprintf(
								/* translators: %s: number of unlinked users */
								_n(
									"<strong>%s user</strong> didn't register through your registration form. Link this user so they can use your form's fields on their frontend profile page.",
									"<strong>%s users</strong> didn't register through your registration form. Link them so they can use your form's fields on their frontend profile page.",
									unlinkedCount,
									"user-registration"
								),
								formatCount(unlinkedCount)
							),
							{
								strong: (
									<Text
										as="strong"
										fontWeight="600 !important"
									/>
								)
							}
						)}
					</Text>

					<Stack
						bg="#f9fafc"
						p="4"
						borderRadius="md"
						spacing="4"
						divider={<StackDivider borderColor="gray.200" />}
					>
						{previewUsers.length > 0 && (
							<HStack spacing="3">
								<AvatarGroup size="sm" spacing="-2">
									{previewUsers.map((user) => (
										<Avatar
											key={user.id}
											name={user.name}
											src={user.avatar}
											borderColor="white"
										/>
									))}
								</AvatarGroup>
								<Text fontSize="14px" color="gray.700">
									{getPreviewNamesText(
										previewUsers,
										unlinkedCount
									)}
								</Text>
							</HStack>
						)}

						<FormControl>
							{forms.length > 1 ? (
								<Flex align="center" wrap="wrap" gap="3">
									<FormLabel
										fontSize={"15px !important"}
										fontWeight="medium"
										whiteSpace="nowrap"
										mb={0}
										me={0}
									>
										{__(
											"Use profile fields from",
											"user-registration"
										)}
									</FormLabel>
									<Select
										value={selectedFormId}
										onChange={(e) =>
											setSelectedFormId(e.target.value)
										}
										bg="white"
										size="sm"
										borderRadius="base"
										flex="1"
										minW="200px"
										maxW="320px"
									>
										{forms.map((form) => (
											<option
												key={form.id}
												value={form.id}
											>
												{form.title}
											</option>
										))}
									</Select>
								</Flex>
							) : (
								<Text fontSize={"15px !important"}>
									{__(
										"Use profile fields from",
										"user-registration"
									)}{" "}
									<Text as="span" fontWeight="600 !important">
										{selectedFormTitle}
									</Text>
								</Text>
							)}
						</FormControl>
					</Stack>

					<HStack justify="space-between" align="center">
						<Button
							colorScheme={"primary"}
							rounded="base"
							onClick={handleMigrate}
							size={"sm"}
							fontSize="14px"
							py={5}
							isLoading={isMigrating}
							isDisabled={isMigrating || isSkipping}
							loadingText={
								linkedSoFar > 0
									? sprintf(
											/* translators: 1: users linked so far, 2: total users to link */
											__(
												"Linking %1$s of %2$s...",
												"user-registration"
											),
											formatCount(linkedSoFar),
											formatCount(unlinkedCount)
										)
									: __("Linking...", "user-registration")
							}
						>
							{sprintf(
								/* translators: %s: number of unlinked users */
								_n(
									"Link %s User",
									"Link %s Users",
									unlinkedCount,
									"user-registration"
								),
								formatCount(unlinkedCount)
							)}
						</Button>

						<Button
							variant="link"
							fontSize="14px"
							fontWeight="normal"
							color="gray.500"
							textDecoration="none"
							_hover={{ textDecoration: "underline" }}
							onClick={handleSkip}
							cursor="pointer"
							width="fit-content"
							isLoading={isSkipping}
							isDisabled={isMigrating || isSkipping}
							loadingText={__("Skipping...", "user-registration")}
						>
							{__("Skip Setup", "user-registration")}
						</Button>
					</HStack>
				</Stack>
			</Collapse>
		</Stack>
	);
};

export default MigrateExistingUsers;
