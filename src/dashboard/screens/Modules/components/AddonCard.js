/**
 * External Dependencies
 */
import React, { useState, useEffect } from "react";
import {
	Box,
	Badge,
	Heading,
	HStack,
	VStack,
	Switch,
	IconButton,
	Link,
	Text,
	Icon,
	Button,
	Spinner,
	Flex,
	Tooltip,
	Modal,
	ModalOverlay,
	ModalContent,
	ModalHeader,
	ModalBody,
	ModalFooter,
	ModalCloseButton,
	useDisclosure
} from "@chakra-ui/react";
import { FaCog, FaPlay, FaLock } from "react-icons/fa";
import ReactPlayer from "react-player";
import { urUtmUrl } from "../../../../utils/utm";
import { activateModule, deactivateModule } from "./modules-api";
import { __ } from "@wordpress/i18n";

const { isMasteriyoActive } =
	typeof _UR_DASHBOARD_ !== "undefined" && _UR_DASHBOARD_;

const AddonCard = ({ addon, showToast }) => {
	const [isActive, setIsActive] = useState(addon.status === "active");
	const [isLoading, setIsLoading] = useState(false);
	const [moduleEnabled, setModuleEnabled] = useState(false);
	const [videoLoading, setVideoLoading] = useState(false);
	const {
		isOpen: isVideoOpen,
		onOpen: onVideoOpen,
		onClose: onVideoClose
	} = useDisclosure();
	const {
		isOpen: isConfirmOpen,
		onOpen: onConfirmOpen,
		onClose: onConfirmClose
	} = useDisclosure();
	const {
		isPro,
		licensePlan,
		urm_is_new_installation,
		urcr_custom_rules_count
	} =
		typeof _UR_DASHBOARD_ !== "undefined" && _UR_DASHBOARD_
			? _UR_DASHBOARD_
			: {};

	// Get assets URL from global variable
	const getImageUrl = (imagePath) => {
		/* global _UR_DASHBOARD_ */
		const { assetsURL } =
			typeof _UR_DASHBOARD_ !== "undefined" && _UR_DASHBOARD_;
		if (imagePath && assetsURL) {
			return assetsURL + imagePath;
		}
		return imagePath;
	};

	// Check if module is enabled based on plan requirements
	useEffect(() => {
		// Special case for content-restriction addon
		if (addon.slug === "user-registration-content-restriction") {
			// For new users, keep the default behavior
			if (urm_is_new_installation) {
				if (addon.plan && addon.plan.includes("free")) {
					setModuleEnabled(true);
				} else if (isPro && licensePlan) {
					const requiredPlan = licensePlan.item_plan.replace(
						" lifetime",
						""
					);
					if (
						addon.plan &&
						addon.plan.includes(requiredPlan.trim())
					) {
						setModuleEnabled(true);
					} else {
						setModuleEnabled(false);
					}
				} else {
					setModuleEnabled(false);
				}
			} else {
				// For old users: only allow enable/disable if currently active and isPro is false
				// After disabling, if not in free plan and not pro, show upgrade plan
				if (!isPro && isActive) {
					// Allow enable/disable only when currently active (check only isActive state, not initial prop)
					setModuleEnabled(true);
				} else if (addon.plan && addon.plan.includes("free")) {
					setModuleEnabled(true);
				} else if (isPro && licensePlan) {
					const requiredPlan = licensePlan.item_plan.replace(
						" lifetime",
						""
					);
					if (
						addon.plan &&
						addon.plan.includes(requiredPlan.trim())
					) {
						setModuleEnabled(true);
					} else {
						setModuleEnabled(false);
					}
				} else {
					// Not active, not in free plan, and not pro - show upgrade plan
					setModuleEnabled(false);
				}
			}
		} else {
			// Default behavior for other addons
			if (addon.plan && addon.plan.includes("free")) {
				setModuleEnabled(true);
			} else if (isPro && licensePlan) {
				const requiredPlan = licensePlan.item_plan.replace(
					" lifetime",
					""
				);
				if (addon.plan && addon.plan.includes(requiredPlan.trim())) {
					setModuleEnabled(true);
				} else {
					setModuleEnabled(false);
				}
			} else {
				setModuleEnabled(false);
			}
		}
	}, [addon.plan, addon.slug, addon.status, isActive]);

	const handleUpgradePlan = () => {
		const { upgradeURL } =
			typeof _UR_DASHBOARD_ !== "undefined" && _UR_DASHBOARD_;
		if (upgradeURL) {
			const plan_upgrade_url = urUtmUrl(upgradeURL, {
				source: "dashboard-all-features",
				medium: "upgrade-link"
			});
			window.open(plan_upgrade_url, "_blank");
		}
	};

	const handleVideoPlay = () => {
		setVideoLoading(true);
		onVideoOpen();
	};

	const handleDeactivateModule = async () => {
		setIsLoading(true);
		try {
			const response = await deactivateModule(addon.slug, addon.type);
			if (response.success) {
				setIsActive(false);
				showToast(
					response.message || "Module deactivated successfully",
					"success"
				);
			} else {
				showToast(
					response.message || "Failed to deactivate module",
					"error"
				);
			}
		} catch (error) {
			showToast(error.message || "An error occurred", "error");
		}
		setIsLoading(false);
		onConfirmClose();
	};

	const handleToggle = async () => {
		// Skip warning modal if isPro is true and slug is user-registration-content-restriction
		// Check if isPro is truthy (handle boolean, string, number formats)
		const isProUser =
			isPro === true || String(isPro).toLowerCase() === "true";
		console.log(isPro);
		// If it's content-restriction addon, isActive, and isPro, skip modal and deactivate directly
		if (
			addon.slug === "user-registration-content-restriction" &&
			isActive &&
			isProUser
		) {
			// Pro users can disable without warning modal - proceed directly to deactivation
			setIsLoading(true);
			try {
				const response = await deactivateModule(addon.slug, addon.type);
				if (response.success) {
					setIsActive(false);
					showToast(
						response.message || "Module deactivated successfully",
						"success"
					);
				} else {
					showToast(
						response.message || "Failed to deactivate module",
						"error"
					);
				}
			} catch (error) {
				showToast(error.message || "An error occurred", "error");
			}
			setIsLoading(false);
			return;
		}

		// Check if we need to show confirmation modal for content-restriction addon when disabling
		// Only show modal if NOT a pro user (and other conditions are met)
		if (
			addon.slug === "user-registration-content-restriction" &&
			isActive &&
			!urm_is_new_installation &&
			!isProUser &&
			urcr_custom_rules_count >= 1
		) {
			onConfirmOpen();
			return;
		}

		setIsLoading(true);
		try {
			let response;
			if (isActive) {
				response = await deactivateModule(addon.slug, addon.type);
				if (response.success) {
					setIsActive(false);
					showToast(
						response.message || "Module deactivated successfully",
						"success"
					);
				} else {
					showToast(
						response.message || "Failed to deactivate module",
						"error"
					);
				}
			} else {
				response = await activateModule(
					addon.slug,
					addon.name,
					addon.type
				);
				if (response.success) {
					setIsActive(true);
					showToast(
						response.message || "Module activated successfully",
						"success"
					);
				} else {
					showToast(
						response.message || "Failed to activate module",
						"error"
					);
				}
			}
		} catch (error) {
			showToast(error.message || "An error occurred", "error");
		}
		setIsLoading(false);
	};

	const getPlanBadge = (plan) => {
		if (plan.includes("free")) return "Free";
		if (plan.includes("personal")) return "Personal";
		if (plan.includes("plus")) return "Plus";
		if (plan.includes("professional")) return "Professional";
		return "Pro";
	};

	const getPlanBadgeColor = (plan) => {
		if (plan.includes("free")) return "gray";
		if (plan.includes("personal")) return "green";
		if (plan.includes("plus")) return "purple";
		if (plan.includes("professional")) return "blue";
		return "gray";
	};

	// Check if this addon should be hidden based on membership-specific rules

	if (addon.slug === "user-registration-membership") {
		// If urm_is_new_installation is set (user is not new), hide the addon
		if (urm_is_new_installation) {
			return null;
		}
		// If urm_is_new_installation is empty (new user) and addon is already active, hide it
		// Check both the state and the initial prop to handle both initial render and after activation
		if (isActive || addon.status === "active") {
			return null;
		}
		// If urm_is_new_installation is empty (new user) and addon is not active, show it
	}

	return (
		<Box
			bg="white"
			borderRadius="lg"
			border="1px solid"
			borderColor="gray.200"
			p="6"
			boxShadow="sm"
			_hover={{ boxShadow: "md" }}
			transition="all 0.2s"
			position="relative"
			height="100%"
			display="flex"
			flexDirection="column"
		>
			{/* Loading Overlay */}
			{isLoading && (
				<Flex
					position="absolute"
					top="0"
					left="0"
					right="0"
					bottom="0"
					bg="rgba(255, 255, 255, 0.8)"
					borderRadius="xl"
					alignItems="center"
					justifyContent="center"
					zIndex="10"
				>
					<Spinner size="lg" color="gray.500" thickness="3px" />
				</Flex>
			)}
			{/* Main Content Layout */}
			<HStack align="start" spacing="4" flex="1" mb="6">
				{/* Left Side - Icon */}
				<Box
					w="10"
					h="10"
					bg="white"
					borderRadius="full"
					display="flex"
					alignItems="center"
					justifyContent="center"
					boxShadow="sm"
					flexShrink={0}
					overflow="hidden"
				>
					{addon.image ? (
						<img
							src={getImageUrl(addon.image)}
							alt={addon.title}
							style={{
								width: "100%",
								height: "100%",
								objectFit: "contain",
								borderRadius: "50%"
							}}
							onError={(e) => {
								// Fallback to placeholder if image fails to load
								e.target.style.display = "none";
								e.target.nextSibling.style.display = "flex";
							}}
						/>
					) : null}
					<Box
						display={addon.image ? "none" : "flex"}
						alignItems="center"
						justifyContent="center"
						fontSize="2xl"
						width="100%"
						height="100%"
					>
						🔧
					</Box>
				</Box>

				{/* Right Side - Title, Description, and Plan Badge */}
				<VStack align="start" spacing="3" flex="1">
					{/* Title and Plan Badge */}
					<HStack justify="space-between" w="full" align="start">
						<Heading
							size="sm"
							color="gray.800"
							fontWeight="600"
							fontSize="16px"
						>
							{addon.title}
						</Heading>
						<Badge
							fontSize="10px"
							px="2"
							py="1"
							borderRadius="base"
							bg={
								getPlanBadge(addon.plan) === "Free"
									? "transparent"
									: getPlanBadge(addon.plan) === "Personal"
										? "#F0FDF4"
										: getPlanBadge(addon.plan) === "Plus"
											? "#f0f3fa"
											: getPlanBadge(addon.plan) ===
												  "Professional"
												? "#EFF6FF"
												: "#EFF6FF"
							}
							border={
								getPlanBadge(addon.plan) === "Free"
									? "1px solid #D1D5DB"
									: getPlanBadge(addon.plan) === "Personal"
										? "1px solid #16A34A"
										: getPlanBadge(addon.plan) === "Plus"
											? "1px solid #92a2e4"
											: getPlanBadge(addon.plan) ===
												  "Professional"
												? "1px solid #BFDBFE"
												: "1px solid #BFDBFE"
							}
							color={
								getPlanBadge(addon.plan) === "Free"
									? "#4B5563"
									: getPlanBadge(addon.plan) === "Personal"
										? "#16A34A"
										: getPlanBadge(addon.plan) === "Plus"
											? "#92a2e4"
											: getPlanBadge(addon.plan) ===
												  "Professional"
												? "#3B82F6"
												: "#3B82F6"
							}
						>
							{getPlanBadge(addon.plan)}
						</Badge>
					</HStack>

					{/* Description */}
					<Tooltip
						label={addon.excerpt}
						placement="top"
						hasArrow
						isDisabled={addon.excerpt.length <= 120}
						backgroundColor="white"
						color={"gray.500"}
						borderRadius="sm"
						p="5"
						cursor="default"
					>
						<Text
							fontSize="13px !important"
							color="gray.500 !important"
							lineHeight="1.5"
							flex="1"
							noOfLines={2}
							cursor={"text"}
							sx={{
								color: "gray.500 !important",
								fontSize: "13px !important"
							}}
						>
							{addon.excerpt}
						</Text>
					</Tooltip>
				</VStack>
			</HStack>

			{/* Footer Section */}
			<HStack justify="space-between" align="center">
				<HStack spacing="3">
					<Link
						href={urUtmUrl(addon.link, {
							source: "dashboard-all-features",
							medium: "button",
							content: "card-documentation-link"
						})}
						fontSize="13px"
						color="#6B7280"
						textDecoration="underline"
						isExternal
					>
						Docs
					</Link>
					{addon.setting_url && isActive && (
						<>
							<Text color="gray.300">|</Text>
							<IconButton
								size="sm"
								icon={<FaCog />}
								aria-label="Settings"
								variant="ghost"
								onClick={() =>
									window.open(addon.setting_url, "_blank")
								}
							/>
						</>
					)}
					{addon.demo_video_url && (
						<>
							<Text color="gray.300">|</Text>
							<IconButton
								size="sm"
								icon={<Icon as={FaPlay} />}
								aria-label="Video Tutorial"
								variant="ghost"
								onClick={handleVideoPlay}
							/>
						</>
					)}
				</HStack>
				<HStack spacing="2">
					{moduleEnabled ? (
						<>
							{" "}
							{"user-registration-masteriyo-course-integration" ===
							addon.slug ? (
								isMasteriyoActive ? (
									<Switch
										isChecked={isActive}
										onChange={handleToggle}
										isDisabled={isLoading}
										sx={{
											"& .chakra-switch__track[data-checked]":
												{
													bg: "#475bb2"
												}
										}}
									/>
								) : (
									<Tooltip
										hasArrow
										placement="top"
										label={__(
											"Masteriyo plugin must be activated to enable this module",
											"user-registration"
										)}
										aria-label="A tooltip"
									>
										<span>
											<Switch
												isChecked={false}
												onChange={handleToggle}
												isDisabled={true}
												sx={{
													"& .chakra-switch__track[data-checked]":
														{
															bg: "#475bb2"
														}
												}}
											/>
										</span>
									</Tooltip>
								)
							) : (
								<Switch
									isChecked={isActive}
									onChange={handleToggle}
									isDisabled={isLoading}
									sx={{
										"& .chakra-switch__track[data-checked]":
											{
												bg: "#475bb2"
											}
									}}
								/>
							)}
						</>
					) : (
						<Button
							size="sm"
							variant="solid"
							fontSize="xs"
							fontWeight="normal"
							bg="#475bb2"
							color="white"
							borderColor="#475bb2"
							_hover={{
								bg: "#3a4a8f",
								borderColor: "#3a4a8f"
							}}
							onClick={handleUpgradePlan}
						>
							Upgrade Plan
						</Button>
					)}
				</HStack>
			</HStack>

			{/* YouTube Video Modal */}
			{isVideoOpen && addon.demo_video_url && (
				<Modal
					isOpen={isVideoOpen}
					onClose={onVideoClose}
					size="3xl"
					isCentered
				>
					<ModalOverlay />
					<ModalContent px={4} pb={4}>
						<ModalHeader textAlign="center">
							{addon.title}
						</ModalHeader>
						<ModalCloseButton />
						<Box position="relative">
							<ReactPlayer
								src={`https://www.youtube.com/embed/${addon.demo_video_url}`}
								playing={true}
								width="100%"
								controls
								onReady={() => setVideoLoading(false)}
								onPlaying={() => setVideoLoading(false)}
								height={360}
							/>
							{videoLoading && (
								<Box
									position="absolute"
									top="50%"
									left="50%"
									transform="translate(-50%, -50%)"
								>
									<Spinner size="lg" />
								</Box>
							)}
						</Box>
					</ModalContent>
				</Modal>
			)}

			{/* Confirmation Modal for Content Restriction */}
			<Modal
				isOpen={isConfirmOpen}
				onClose={onConfirmClose}
				size="md"
				isCentered
			>
				<ModalOverlay />
				<ModalContent>
					<ModalHeader color="red.600">
						Warning: Potential Data Loss
					</ModalHeader>
					<ModalCloseButton />
					<ModalBody>
						<VStack spacing="4" align="start">
							<Text color="gray.700" lineHeight="1.6">
								You are about to disable the Content Restriction
								addon. Please be aware that:
							</Text>
							<Box as="ul" pl="5" color="gray.600">
								<Text as="li" mb="2">
									There might be existing content restriction
									rules in your system
								</Text>
								<Text as="li" mb="2">
									If you continue, you may lose access to
									these rules
								</Text>
								<Text as="li">
									You will need to upgrade to a higher plan to
									access them again
								</Text>
							</Box>
							<Text color="gray.700" fontWeight="medium">
								Are you sure you want to continue?
							</Text>
						</VStack>
					</ModalBody>
					<ModalFooter>
						<Button
							variant="ghost"
							mr={3}
							onClick={onConfirmClose}
							isDisabled={isLoading}
						>
							Cancel
						</Button>
						<Button
							colorScheme="red"
							onClick={handleDeactivateModule}
							isLoading={isLoading}
						>
							Continue Anyway
						</Button>
					</ModalFooter>
				</ModalContent>
			</Modal>
		</Box>
	);
};

export default AddonCard;
