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
		// Set first so the upgrade/plugin modals attribute the clicked template.
		setSelectedTemplateSlug(template.slug);
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
			<VStack
				position="relative"
				bg="white"
				border="1px solid rgba(0, 0, 0, 0.08)"
				borderRadius="13px"
				p="26px 30px"
				mb="32px"
				overflow="hidden"
				display="flex"
				alignItems="flex-start"
				width="100%"
				sx={{
					'::before': {
					content: '""',
					position: "absolute",
					inset: "0",
					bg: "radial-gradient(ellipse 60% 120% at 100% 50%, rgba(64, 99, 240, 0.07) 0%, transparent 70%), radial-gradient(ellipse 40% 80% at 80% 20%, rgba(61, 126, 245, 0.06) 0%, transparent 60%)",
					pointerEvents: "none",
					},
				}}
				>
				<Text
					display="inline-block"
					alignItems="center"
					gap="6px"
					bg="rgba(66, 64, 240, 0.08)"
					border="1px solid rgba(64, 92, 240, 0.2)"
					borderRadius="20px"
					p="4px 12px"
					fontSize="12px !important"
					lineHeight="1.5 !important"
					fontWeight="medium !important"
					color="#475bb2 !important"
					letterSpacing="0.23px"
					m="0 0 12px"
				>✦ {__('Ready-made templates', 'user-registration')}</Text>
				<Heading
					as="h2"
					fontSize="26px"
					lineHeight="1.2"
					m="0 0 8px !important"
					color="#0f0f1a !important"
					fontWeight="700 !important"
					fontFamily={"inherit"}
				>
					{__("Build faster with beautiful templates", "user-registration")}
				</Heading>
				<Text
					fontSize="14px"
					lineHeight="1.6"
					fontWeight="400"
					color="#4A5568"
					maxWidth="480px"
					m={0}
				>
					{__(
						"Pick from 50 professionally designed form templates. Customize, deploy, and start collecting responses in minutes.",
						"user-registration"
					)}
				</Text>
			</VStack>
		
			<Heading
				as="h3"
				fontSize="18px"
				lineHeight="26px"
				letterSpacing="0.2px"
				fontWeight="semibold"
				m="0px 0px 32px !important"
				color="#26262E"
				borderBottom="1px solid #e1e1e1"
				paddingBottom="12px"
			>
				{selectedCategory}
			</Heading>
			{templates?.length ? (
				<SimpleGrid gridTemplateColumns="repeat(auto-fill, minmax(280px, 1fr))" spacing={6}>
					{templates.map((template) => {
						if (template.slug === 'blank') {
							return (
								<Box
									key={template.slug}
									borderWidth="2px"
									borderStyle="dashed"
									borderColor="#c3c3c3"
									borderRadius="13px"
									bg="white"
									display="flex"
									flexDirection="column"
									minHeight="320px"
									alignItems="center"
									justifyContent="center"
									cursor="pointer"
									transition="all .3s"
									onClick={() => handleTemplateClick(template)}
									_hover={{
										borderColor: '#475bb2',
										boxShadow: '0px 5px 24px rgba(58, 34, 93, 0.12)',
									}}
								>
									<Center
										bg="rgba(71, 91, 178, 0.10)"
										borderRadius="50%"
										w="48px"
										h="48px"
										mb="16px"
									>
										<Icon viewBox="0 0 24 24" boxSize={6} color="#475bb2">
											<path fill="currentColor" d="M19 11h-6V5a1 1 0 0 0-2 0v6H5a1 1 0 0 0 0 2h6v6a1 1 0 0 0 2 0v-6h6a1 1 0 0 0 0-2z" />
										</Icon>
									</Center>
									<Heading fontSize="18px" fontWeight="600" mb="8px !important" color="#0f0f1a">
										{__('Start Blank', 'everest-forms')}
									</Heading>
									<Text fontSize="14px" color="gray.500" m={0}>
										{__('Create from scratch', 'user-registration')}
									</Text>
								</Box>
							);
						}

						return (
						<Box
							key={template.slug}
							border="1px solid #e1e1e1"
							borderRadius="8px"
							overflow="hidden"
							position="relative"
							onMouseOver={() => setHoverCardId(template.id)}
							onMouseLeave={() => setHoverCardId(null)}
							textAlign="center"
							bg="white"
							p={0}
							transition="all .3s"
							_hover={{
								borderColor:"transparent",
								boxShadow:
									"0px 5px 24px rgba(58, 34, 93, 0.12)",
								'::before': {
									content: '""',
									position: 'absolute',
									top: 0,
									left: 0,
									width: '100%',
									height: '250px',
									bg: "#181818",
									opacity: ".5",
									zIndex: 1,
								},
								"& > div > .template-title": {
									color: "#475bb2"
								}
							}}
						>
							<Center mb={0}>
								<Box
									position="relative"
									width="100%"
									height="250px"
									display="flex"
									justifyContent="center"
									alignItems="flex-start"
									bg= { 'linear-gradient(129deg, #F3F2F8 2.83%, #F7F5F9 110.96%)' }
									p="10px 18px 0"
									borderRadius="6px 6px 0px 0px"
									overflow="hidden"
									transition="all .3s"
									borderBottom="1px solid #e1e1e1"
								>
									<Image
										boxShadow="0 6px 14px 0 #E5E1EF"
										src={modifyImageUrl(template.imageUrl)}
										alt={template.title}
										objectFit="contain"
										borderRadius="6px"
										marginTop="8px"
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
												fontSize="11px"
												fontWeight="semibold"
												p="4px 8px"
												textTransform="uppercase"
												zIndex="2"
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
												fontSize="11px"
												p="4px 8px"
												textTransform="uppercase"
												zIndex="2"
												fontWeight="semibold"
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
										<HStack
											spacing={3}
											position="absolute"
											top="50%"
											left="50%"
											transform="translate(-50%, -50%)"
											zIndex={2}
										>
											<Button
												borderRadius="4px"
												fontSize="14px"
												lineHeight="24px"
												fontWeight="medium"
												p="0 16px"
												minWidth="auto"
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
													"Use Template",
													"user-registration"
												)}
											</Button>
											{template.preview_link && (
												<Button
													borderRadius="4px"
													fontSize="14px"
													lineHeight="24px"
													fontWeight="medium"
													p="0 16px"
													minWidth="auto"
													color="#0f0f1a"
													bg="#f4f4f4"
													border="1px solid rgba(0,0,0,0.12)"
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
													ml="0px !important"
												>
													{__(
														"Preview",
														"user-registration"
													)}
												</Button>
											)}
										</HStack>
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
									top={3}
									right={3}
									zIndex={3}
									bg="transparent"
									border="none"
									display="flex"
									alignItems="center"
									justifyContent="center"
									_hover={{ color: "red.600" }}
								>
									<Icon
										as={
											favorites.includes(template.slug)
												? FaHeart
												: FaRegHeart
										}
										boxSize={5}
										color={
											favorites.includes(template.slug)
												? "red"
												: "white"
										}
									/>
								</Box>
							)}

							<VStack 
								p="16px"
								alignItems="flex-start"
								>
								<Heading
									className="template-title"
									width="100%"
									textAlign="left"
									fontWeight="bold"
									fontSize="16px"
									margin="0px"
									lineHeight="24px"
								>
									{template.title}
								</Heading>
								<Text
									textAlign="left"
									margin="0px"
									fontSize="14px"
									fontWeight="400"
									color="gray.600"
									lineHeight="22px"
								>
									{template.description}
								</Text>
							</VStack>
						</Box>
					)})}
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
									medium: "popup",
									content:
										selectedTemplateSlug ||
										"premium-template"
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
