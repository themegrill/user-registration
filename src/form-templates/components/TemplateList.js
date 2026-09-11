import React, { useState, useEffect } from "react";
import {
	SimpleGrid,
	Box,
	Image,
	Text,
	Badge,
	Button,
	Modal,
	ModalOverlay,
	ModalContent,
	ModalHeader,
	ModalBody,
	ModalCloseButton,
	useDisclosure,
	Input,
	VStack,
	Divider,
	useToast,
	HStack,
	Icon,
	Heading,
	Center,
	ModalFooter,
	Spacer
} from "@chakra-ui/react";
import apiFetch from "@wordpress/api-fetch";
import PluginStatus from "./PluginStatus";
import { FaHeart } from "react-icons/fa";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { __, sprintf } from "@wordpress/i18n";
import { IoPlayOutline } from "react-icons/io5";
import { FaRegHeart } from "react-icons/fa";
import { MdOutlineRemoveRedEye } from "react-icons/md";
import { urUtmUrl } from "../../utils/utm";

const { security, siteURL } = ur_templates_script;

const LockIcon = (props) => (
	<Icon viewBox="0 0 24 24" {...props}>
		<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 54 54">
			<rect width="54" height="54" fill="#FA5252" rx="27" />
			<path
				fill="#fff"
				d="M34 22.334h-1.166v-1.167A5.84 5.84 0 0 0 27 15.334a5.84 5.84 0 0 0-5.833 5.833v1.167H20a2.333 2.333 0 0 0-2.333 2.333v11.667A2.333 2.333 0 0 0 20 38.667h14a2.333 2.333 0 0 0 2.334-2.333V24.667A2.333 2.333 0 0 0 34 22.334Zm-10.5-1.167c0-1.93 1.57-3.5 3.5-3.5s3.5 1.57 3.5 3.5v1.167h-7v-1.167Zm4.667 10.177v2.657h-2.333v-2.657a2.323 2.323 0 0 1-.484-3.66 2.333 2.333 0 0 1 3.984 1.65c0 .861-.473 1.605-1.167 2.01Z"
			/>
		</svg>
	</Icon>
);

const TemplateList = ({ selectedCategory, templates }) => {
	const [previewTemplate, setPreviewTemplate] = useState(null);
	const [formTemplateName, setFormTemplateName] = useState("");
	const [licenseDetail, setLicenseDetail] = useState([]);
	const [userLicensePlan, setUserLicensePlan] = useState("");
	const [selectedTemplateSlug, setSelectedTemplateSlug] = useState("");
	const { isOpen, onOpen, onClose } = useDisclosure();
	const [hoverCardId, setHoverCardId] = useState(null);
	const [favorites, setFavorites] = useState([]);
	const toast = useToast();
	const queryClient = useQueryClient();
	const [isPluginModalOpen, setIsPluginModalOpen] = useState(false);
	const [upgradePlan, setUpgradePlan] = useState(false);

	const openModal = () => onOpen();
	const closeModal = () => onClose();

	useEffect(() => {
		const fetchFavorites = async () => {
			try {
				const response = await apiFetch({
					path: `user-registration/v1/form-templates/favorite_forms`,
					method: "GET",
					headers: {
						"X-WP-Nonce": security
					}
				});

				if (response && response.success) {
					let userfavourites = Object.keys(response.favorites).map(
						(key) => response.favorites[key]
					);
					setFavorites(userfavourites);
				}
			} catch (error) {
				console.error("Error fetching favorites:", error);
			}
		};
		fetchFavorites();

		const fetchLicenseStatus = async () => {
			try {
				const response = await apiFetch({
					path: `user-registration/v1/plugin/get_plan`,
					method: "GET",
					headers: {
						"X-WP-Nonce": security
					}
				});

				if (response.license_plan) {
					setLicenseDetail(true);
					setUserLicensePlan(response.license_plan);
				}
			} catch (error) {
				// console.log(error);
			}
		};

		fetchLicenseStatus();
	}, []);

	const handleTemplateClick = async (template) => {
		const requiredPlugins = template.addons
			? Object.keys(template.addons)
			: [];

		if (template.isPro) {
			let activatedLicensePlan = userLicensePlan
				.toLocaleLowerCase()
				.replace("user registration", "")
				.replace("lifetime", "")
				.trim();

			let requiredLicensePlan = {};
			let setRequiredLicensePlan = template.plan[0];
			switch (setRequiredLicensePlan) {
				case "personal":
					requiredLicensePlan = [
						"personal",
						"plus",
						"professional",
						"themegrill agency"
					];
					break;
				case "plus":
					requiredLicensePlan = [
						"plus",
						"professional",
						"themegrill agency"
					];
					break;
			}

			if (requiredLicensePlan.indexOf(activatedLicensePlan) < 0) {
				setUpgradePlan(true);
				setIsPluginModalOpen(true);
			} else {
				setUpgradePlan(false);
				setIsPluginModalOpen(false);
			}
		} else {
			setUpgradePlan(false);
		}

		try {
			const response = await apiFetch({
				path: `user-registration/v1/plugin/upgrade`,
				method: "POST",
				body: JSON.stringify({ requiredPlugins }),
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": security
				}
			});

			const { plugin_status } = response;
			if (!plugin_status) {
				setFormTemplateName(template.title);
				setIsPluginModalOpen(true);
				return;
			}

			setSelectedTemplateSlug(template.slug);
			setPreviewTemplate(template);
			setFormTemplateName(template.title);
			openModal();
		} catch (error) {
			console.log(error);
			toast({
				title: __("Error", "user-registration"),
				description: __(
					"An error occurred while checking the plugin status. Please try again.",
					"user-registration"
				),
				status: "error",
				position: "bottom-right",
				duration: 5000,
				isClosable: true,
				variant: "subtle"
			});
		}
	};

	const handleFormTemplateSave = async () => {
		if (!formTemplateName) {
			toast({
				title: __("Form name required", "user-registration"),
				description: __(
					"Please provide a name for your form.",
					"user-registration"
				),
				status: "warning",
				position: "bottom-right",
				duration: 5000,
				isClosable: true,
				variant: "subtle"
			});
			return;
		}

		try {
			const response = await apiFetch({
				path: `user-registration/v1/form-templates/create`,
				method: "POST",
				body: JSON.stringify({
					title: formTemplateName,
					slug: selectedTemplateSlug
				}),
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": security
				}
			});

			if (response.success && response.data) {
				window.location.href = response.data.redirect;
			} else {
				toast({
					title: __("Error", "user-registration"),
					description:
						response.message ||
						__(
							"Failed to create form template.",
							"user-registration"
						),
					status: "error",
					position: "bottom-right",
					duration: 5000,
					isClosable: true,
					variant: "subtle"
				});
			}
		} catch (error) {
			toast({
				title: __("Error", "user-registration"),
				description: __(
					"An error occurred while creating the form template.",
					"user-registration"
				),
				status: "error",
				position: "bottom-right",
				duration: 5000,
				isClosable: true,
				variant: "subtle"
			});
		}
	};

	const mutation = useMutation({
		mutationFn: async (slug) => {
			const newFavorites = favorites.includes(slug)
				? favorites.filter((item) => item !== slug)
				: [...favorites, slug];

			setFavorites(newFavorites);

			await apiFetch({
				path: `user-registration/v1/form-templates/favorite`,
				method: "POST",
				body: JSON.stringify({
					action: newFavorites.includes(slug)
						? "add_favorite"
						: "remove_favorite",
					slug
				}),
				headers: {
					"Content-Type": "application/json",
					"X-WP-Nonce": security
				}
			});

			return newFavorites;
		},
		onError: (error) => {
			toast({
				title: __("Error", "user-registration"),
				description: __(
					"An error occurred while updating favorites.",
					"user-registration"
				),
				status: "error",
				position: "bottom-right",
				duration: 5000,
				isClosable: true,
				variant: "subtle"
			});
		},
		onSuccess: (newFavorites) => {
			queryClient.invalidateQueries(["templates"]);
			setFavorites(newFavorites);
			queryClient.invalidateQueries(["favorites"]);
		}
	});

	const handleFavoriteToggle = (slug) => {
		mutation.mutate(slug);
	};

	const modifyImageUrl = (url) => {
		const urlParts = url.split("/");
		const fileName = urlParts.pop();
		if (fileName === "blank.png") {
			return url;
		}
		return [...urlParts, `user-registration-${fileName}`].join("/");
	};

	const addonEntries = previewTemplate?.addons
		? Object.entries(previewTemplate.addons).map(([key, value]) => ({
				key,
				value
			}))
		: [];

	const requiredPlugins = addonEntries.map((addon) => ({
		key: addon.key,
		value: addon.value
	}));

	return (
		<Box>
			<Heading
				as="h3"
				fontSize="18px"
				lineHeight="26px"
				fontWeight="semibold"
				m="0px 0px 32px"
				color="#26262E"
				borderBottom="1px solid #e1e1e1"
				paddingBottom="20px"
				mb="30px !important"
			>
				{selectedCategory.toUpperCase()}
			</Heading>
			{templates?.length ? (
				<SimpleGrid
					columns={{ base: 1, md: 2, lg: 2, xl: 3, "2xl": 4 }}
					spacing={6}
				>
					{templates.map((template) => (
						<Box
							key={template.slug}
							borderWidth="2px"
							borderRadius="8px"
							borderColor="#F6F4FA"
							overflow="hidden"
							position="relative"
							onMouseOver={() => setHoverCardId(template.id)}
							onMouseLeave={() => setHoverCardId(null)}
							textAlign="center"
							bg="white"
							p={0}
							transition="all .3s"
							_hover={{
								boxShadow:
									"0px 5px 24px rgba(58, 34, 93, 0.12)",
								"::before": {
									content: '""',
									position: "absolute",
									top: 0,
									left: 0,
									width: "100%",
									height: "207px",
									bg: "rgba(0, 0, 0, 0.4)",
									zIndex: 1
								},
								"& > div > .template-title": {
									color: "#475bb2"
								}
							}}
						>
							<Center mb={0} height="207px">
								<Box
									position="relative"
									width="100%"
									height="100%"
									display="flex"
									justifyContent="center"
									alignItems="center"
									bg="#ECECF6"
									borderRadius="4px 4px 0px 0px"
									overflow="hidden"
									transition="all .3s"
								>
									<Image
										boxShadow={
											template.slug == "blank"
												? "none"
												: "0px 3px 12px rgba(58, 34, 93, 0.12)"
										}
										src={modifyImageUrl(template.imageUrl)}
										alt={template.title}
										objectFit="contain"
										height="100%"
									/>
									{template.isPro ? (
										!licenseDetail ? (
											<Badge
												bg="#4BCE61"
												color="white"
												position="absolute"
												bottom="12px"
												right="12px"
												borderRadius="4px"
												fontSize="12px"
												p="2px 6px"
												textTransform="capitalize"
												zIndex="2"
												fontWeight="500"
											>
												{__("Pro", "user-registration")}
											</Badge>
										) : (
											<Badge
												bg="#4BCE61"
												color="white"
												position="absolute"
												bottom="12px"
												right="12px"
												borderRadius="4px"
												fontSize="12px"
												p="2px 6px"
												textTransform="capitalize"
												zIndex="2"
												fontWeight="500"
											>
												{template.plan?.[0] ||
													__(
														"Pro",
														"user-registration"
													)}
											</Badge>
										)
									) : null}
									{/* Hover Buttons */}
									{hoverCardId === template.id && (
										<VStack
											spacing={4}
											position="absolute"
											top="50%"
											left="50%"
											transform="translate(-50%, -50%)"
											zIndex={2}
										>
											<Button
												borderRadius="50px"
												leftIcon={<IoPlayOutline />}
												style={{
													borderColor: "#475bb2",
													color: "white"
												}}
												onClick={() =>
													handleTemplateClick(
														template
													)
												}
												bg="#475bb2"
												_hover={{ bg: "#4153A2" }}
												width="100%"
											>
												{__(
													"Get Started",
													"user-registration"
												)}
											</Button>
											{template.preview_link && (
												<Button
													borderRadius="50px"
													leftIcon={
														<MdOutlineRemoveRedEye />
													}
													color="#4D4D4D"
													variant="outline"
													onClick={() =>
														window.open(
															template.preview_link,
															"_blank"
														)
													}
													_hover={{
														color: "#4D4D4D",
														bg: "#EFEFEF"
													}}
													bg="#FFFFFF"
													width="100%"
												>
													{__(
														"Preview",
														"user-registration"
													)}
												</Button>
											)}
										</VStack>
									)}
								</Box>
							</Center>

							{hoverCardId === template.id && (
								<Box
									as="button"
									onClick={() =>
										handleFavoriteToggle(template.slug)
									}
									aria-label={`Toggle favorite for ${template.title}`}
									position="absolute"
									top={2}
									right={2}
									zIndex={3}
									bg="transparent"
									border="none"
									_hover={{ color: "red.600" }}
								>
									<Icon
										as={
											favorites.includes(template.slug)
												? FaHeart
												: FaRegHeart
										}
										boxSize={6}
										color={
											favorites.includes(template.slug)
												? "red"
												: "white"
										}
									/>
								</Box>
							)}

							<VStack padding="8px 16px 16px 16px" gap="10px">
								<Heading
									className="template-title"
									width="100%"
									textAlign="left"
									fontWeight="500"
									fontSize="16px"
									margin="0px"
									lineHeight="22px"
								>
									{template.title}
								</Heading>
								<Text
									textAlign="left"
									margin="0px"
									fontSize="14px"
									fontWeight="400"
									color="gray.600"
									lineHeight="25px"
								>
									{template.description}
								</Text>
							</VStack>
						</Box>
					))}
				</SimpleGrid>
			) : (
				<Box
					display="flex"
					flexDirection="column"
					justifyContent="center"
					alignItems="center"
					height="80vh"
					width="100%"
				>
					<Image
						src={
							siteURL +
							"/wp-content/plugins/user-registration/assets/images/empty-table.png"
						}
						alt={__("Not Found", "user-registration")}
						boxSize="300px"
						objectFit="cover"
					/>
					<Text
						mt={4}
						fontSize="lg"
						fontWeight="bold"
						textAlign="center"
					>
						{__("No Templates Found", "user-registration")}
					</Text>
					<Text
						margin={0}
						fontSize="sm"
						textAlign="center"
						color="gray.600"
					>
						{__(
							"Sorry, we didn't find any templates that match your criteria",
							"user-registration"
						)}
					</Text>
				</Box>
			)}
			<Modal
				isCentered
				isOpen={isPluginModalOpen}
				onClose={() => setIsPluginModalOpen(false)}
				size="lg"
			>
				<ModalOverlay />
				<ModalContent borderRadius="8px" padding="20px">
					<ModalHeader
						padding="0px"
						textAlign="center"
						fontSize="20px"
						lineHeight="28px"
						color="#26262E"
					>
						<LockIcon boxSize={10} />
						<Heading
							as="h2"
							margin="10px 0px 0px 0px"
							fontSize="20px"
							lineHeight="28px"
							fontWeight="bold"
						>
							{upgradePlan
								? sprintf(
										__(
											"%s Requires License Upgrade",
											"user-registration"
										),
										formTemplateName
									)
								: sprintf(
										__(
											"%s is a Premium Template",
											"user-registration"
										),
										formTemplateName
									)}
						</Heading>
					</ModalHeader>
					<ModalCloseButton top="12px" right="12px" />
					<ModalBody
						padding="0px"
						marginTop="16px"
						textAlign="center"
					>
						<Text
							margin="0px"
							fontSize="16px"
							lineHeight="24px"
							mb="20px"
						>
							{upgradePlan
								? __(
										"This template requires plus and above plan. Please upgrade to the Plus and above to unlock all these awesome templates.",
										"user-registration"
									)
								: __(
										"This template requires premium addons. Please upgrade to the Premium to unlock all these awesome templates.",
										"user-registration"
									)}
						</Text>
					</ModalBody>
					<ModalFooter justifyContent="flex-end" padding="0px">
						<Button
							variant="ghost"
							onClick={() => setIsPluginModalOpen(false)}
							border="1px solid #DFDFDF"
						>
							{__("OK", "user-registration")}
						</Button>
						<a
							href={urUtmUrl(
								"https://wpuserregistration.com/upgrade/",
								{
									source: "form-template",
									medium: "popup"
								}
							)}
							target="_blank"
							rel="noopener noreferrer"
							style={{
								width: "inherit"
							}}
						>
							<Button
								style={{
									backgroundColor: "#475BB2",
									color: "#FFFFFF"
								}}
								ml={3}
							>
								{__("Upgrade Plan", "user-registration")}
							</Button>
						</a>
					</ModalFooter>
				</ModalContent>
			</Modal>

			<Modal
				isCentered
				isOpen={isOpen && !upgradePlan}
				onClose={onClose}
				size="xl"
			>
				<ModalOverlay />
				<ModalContent borderRadius="8px" padding="40px">
					<ModalHeader
						padding="0px"
						textAlign="left"
						fontSize="20px"
						lineHeight="28px"
						color="#26262E"
					>
						{__(
							"Uplift your form experience to the next level.",
							"user-registration"
						)}
					</ModalHeader>
					<ModalCloseButton top="12px" right="12px" />
					<ModalBody padding="0px" marginTop="16px">
						<Box mb="20px" padding="0px">
							<Text
								margin="0px 0px 6px"
								fontSize="16px"
								lineHeight="29px"
							>
								{__("Give it a name", "user-registration")}
							</Text>
							<Input
								width={"full"}
								value={formTemplateName}
								onChange={(e) =>
									setFormTemplateName(e.target.value)
								}
								placeholder="Give it a name."
								size="md"
								_focus={{
									borderColor: "#475BB2",
									outline: "none",
									boxShadow: "none"
								}}
							/>
						</Box>

						<Box overflow="hidden" mb="0px" padding="0px">
							<PluginStatus
								requiredPlugins={requiredPlugins}
								onActivateAndContinue={handleFormTemplateSave}
							/>
						</Box>
					</ModalBody>
				</ModalContent>
			</Modal>
		</Box>
	);
};

export default TemplateList;
