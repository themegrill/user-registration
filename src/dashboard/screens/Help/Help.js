/**
 *  External Dependencies
 */
import {
	AspectRatio,
	Box,
	Button,
	ButtonGroup,
	Grid,
	Heading,
	HStack,
	Image,
	Link,
	Stack,
	Text
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import React, { useState, useEffect } from "react";
import { BiBulb } from "react-icons/bi";

/**
 *  Internal Dependencies
 */
import * as Icon from "../../components/Icon/Icon";
import * as URIcon from "../../components/Icon/Icon";
import facebook from "../../images/facebook.webp";
import x from "../../images/x.webp";
import youtube from "../../images/youtube.webp";
import ShortcodesLists from "./Lists/ShortcodesLists/ShortcodesLists";
import SmartTagsLists from "./Lists/SmartTagsLists/SmartTagsLists";
import { urUtmUrl } from "../../../utils/utm";

const PRIMARY_COLOR = "#475bb2";
const docURL = "https://docs.wpuserregistration.com/";
const facebookGroup = "https://www.facebook.com/groups/userregistration";
const featureRequestURL = "https://wpuserregistration.com/feature-requests/";
const submitReviewUrl =
	"https://wordpress.org/support/plugin/user-registration/reviews/?rate=5#new-post";
const ticketUrl = "https://wpuserregistration.com/support/";

const Help = () => {
	/* global _UR_DASHBOARD_ */
	const { newFormURL, allFormsURL } =
		typeof _UR_DASHBOARD_ !== "undefined" && _UR_DASHBOARD_;

	const [isListViewerOpen, setIsListViewerOpen] = useState(false);
	const [listViewerType, setListViewerType] = useState("");

	useEffect(() => {}, [isListViewerOpen]);

	const supportURL = urUtmUrl("https://wpuserregistration.com/support/", {
			source: "dashboard-help",
			medium: "button",
			content: "support"
		}),
		helpURL = urUtmUrl("https://docs.wpuserregistration.com/", {
			source: "dashboard-help",
			medium: "button",
			content: "documentation"
		}),
		featureRequestURL = urUtmUrl(
			"https://wpuserregistration.com/feature-requests/",
			{
				source: "dashboard-help",
				medium: "menu-link",
				content: "feature-requests"
			}
		);
	return (
		<Grid
			my="8"
			mx="6"
			gridGap="5"
			gridTemplateColumns={{
				sm: "1fr",
				md: "3fr 1fr"
			}}
		>
			<Stack gap="5">
				{isListViewerOpen ? (
					listViewerType === "shortcodes" ? (
						<ShortcodesLists
							setIsListViewerOpen={setIsListViewerOpen}
						/>
					) : (
						<SmartTagsLists
							setIsListViewerOpen={setIsListViewerOpen}
						/>
					)
				) : (
					<>
						{/* Welcome Section and Video Section - Combined in Single Container */}
						{/* <Box
							p="6"
							borderRadius="base"
							border="1px"
							borderColor="gray.100"
							bgColor="white"
							boxShadow="sm"
							mb="5"
						>
							<Grid
								gridTemplateColumns={{
									sm: "1fr",
									md: "1fr 1fr"
								}}
								gridGap="8"
								h="100%"
							>
								{/* Welcome Section - Left */}
						{/* <Stack gap="4" justify="center">
									<Heading
										as="h2"
										fontSize="2xl"
										fontWeight="semibold"
										color="gray.800"
									>
										{__(
											"Welcome to User Registration & Membership!",
											"user-registration"
										)}
									</Heading>
									<Text fontSize="md" color="gray.600" lineHeight="1.5">
										{__(
											"Create powerful registration forms and manage your members with our comprehensive toolkit.",
											"user-registration"
										)}
									</Text>
									<Stack gap="3" direction={{ sm: "column", md: "row" }}>
										<Button
											as={Link}
											bgColor="#475bb2"
											color="white"
											size="md"
											borderRadius="base"
											fontSize="14px"
											fontWeight="normal"
											href={newFormURL}
											isExternal
											textDecor="none !important"
											_hover={{
												bgColor: "#3a4a9a",
												color: "white",
												textDecor: "none !important"
											}}
										>
											{__("Create a Registration Form", "user-registration")}
										</Button>
										<Button
											as={Link}
											variant="outline"
											color="#475bb2"
											borderColor="#475bb2"
											borderRadius="base"
											fontSize="14px"
											fontWeight="normal"
											href={allFormsURL}
											isExternal
											textDecor="none !important"
										>
											{__("View all forms", "user-registration")}
										</Button>
									</Stack>
								</Stack> */}

						{/* Video Section - Right */}
						{/* <Box>
									<AspectRatio ratio={16 / 9}>
										<iframe
											src="https://www.youtube.com/embed/ZOXVbfBTNPQ?si=82Q2iOOE2iUF7M02&autoplay=1&mute=1&rel=0"
											title="Best WordPress User Registration & Membership"
											style={{
												borderRadius: "11px",
												border: "none",
												overflow: "hidden"
											}}
											allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
											allowFullScreen
										></iframe>
									</AspectRatio>
								</Box>
							</Grid> */}
						{/* </Box>  */}

						{/* Getting started and support */}
						<Grid
							gridTemplateColumns={{
								sm: "1fr",
								md: "1fr 1fr"
							}}
							gridGap="5"
						>
							<Stack
								px="6"
								py="8"
								align="center"
								gap="3"
								bgColor="white"
								borderRadius="base"
								fontSize="14px"
								fontWeight="normal"
							>
							<URIcon.DocsLines
								w="5"
								h="5"
								fill={PRIMARY_COLOR}
							/>
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
								>
									{__(
										"Getting  Started",
										"user-registration"
									)}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Check our documentation for detailed information on User Registration & Membership features and how to use them.",
										"user-registration"
									)}
								</Text>
								<Button
									mt="10"
									variant="outline"
									color="#475bb2"
									borderColor="#475bb2"
									borderRadius="base"
									fontSize="14px"
									fontWeight="normal"
								>
									<Link
										href={docURL}
										isExternal
										textDecoration="none"
										_hover={{ textDecoration: "none" }}
										fontSize="13px"
										fontWeight="normal"
									>
										{__(
											"View Documentation",
											"user-registration"
										)}
									</Link>
								</Button>
							</Stack>
							<Stack
								px="6"
								py="8"
								align="center"
								gap="3"
								bgColor="white"
								borderRadius="base"
								fontSize="14px"
								fontWeight="normal"
							>
							<URIcon.Headphones
									w="5"
									h="5"
									fill={PRIMARY_COLOR}
								/>
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
								>
									{__(
										"Support",
										"user-registration"
									)}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Submit a ticket for encountered issues and get help from our support team instantly.",
										"user-registration"
									)}
								</Text>
							<Button
									mt="10"
									variant="outline"
									color="#475bb2"
									borderColor="#475bb2"
									borderRadius="base"
									fontSize="14px"
									fontWeight="normal"
								>
								<Link
									href={ticketUrl}
									isExternal
									textDecoration="none"
									_hover={{ textDecoration: "none" }}
									fontSize="13px"
									fontWeight="normal"
								>
									{__("Create a Ticket", "user-registration")}
								</Link>
							</Button>
						</Stack>
						</Grid>

						{/* Shortcodes and Smart Tags Section */}
						<Grid
							gridTemplateColumns={{
								sm: "1fr",
								md: "1fr 1fr"
							}}
							gridGap="5"
						>
							<Stack
								px="6"
								py="8"
								align="center"
								gap="3"
								bgColor="white"
								borderRadius="base"
								border="1px"
								borderColor="gray.100"
								textAlign="center"
							>
								<Icon.Shortcode w="8" h="8" fill="#475bb2" />
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
								>
									{__("Shortcodes", "user-registration")}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Find the complete list of shortcodes with their usage information and parameter details.",
										"user-registration"
									)}
								</Text>
								<Button
									mt="10"
									variant="outline"
									color="#475bb2"
									borderColor="#475bb2"
									borderRadius="base"
									fontSize="14px"
									fontWeight="normal"
									onClick={() => {
										setIsListViewerOpen(true);
										setListViewerType("shortcodes");
									}}
								>
									{__(
										"View all Shortcodes",
										"user-registration"
									)}
								</Button>
							</Stack>
							<Stack
								px="6"
								py="8"
								align="center"
								gap="3"
								bgColor="white"
								borderRadius="base"
								border="1px"
								borderColor="gray.100"
								textAlign="center"
							>
								<Icon.SmartTag w="8" h="8" fill="#475bb2" />
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
								>
									{__("Smart Tags", "user-registration")}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Find the complete list of smart tags with their usage information and parameter details.",
										"user-registration"
									)}
								</Text>
								<Button
									mt="10"
									variant="outline"
									color="#475bb2"
									borderColor="#475bb2"
									borderRadius="base"
									fontSize="14px"
									fontWeight="normal"
									onClick={() => {
										setIsListViewerOpen(true);
										setListViewerType("smartTags");
									}}
								>
									{__("View Tags", "user-registration")}
								</Button>
							</Stack>
						</Grid>
					</>
				)}
				<Stack marginTop="25px">
					<Heading as="h3" fontSize="lg" fontWeight="semibold">
						{__("Join Our Community", "user-registration")}
					</Heading>
				</Stack>
				<Grid
					gridTemplateColumns="repeat(3, 1fr)"
					gap="6"
					marginTop="20px"
				>
					{/* Facebook Community Card */}
					<Stack
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
						overflow="hidden"
						boxShadow="sm"
						h="100%"
					>
						<Box p="6" textAlign="center" position="relative">
							<Image src={facebook} />
						</Box>
						<Stack p="6" gap="3" flex="1" justify="space-between">
							<Stack gap="3">
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
									color="gray.800"
								>
									{__(
										"Facebook Community",
										"user-registration"
									)}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Join our exclusive group and connect with fellow members. Ask questions, contribute to discussions, and share feedback!",
										"user-registration"
									)}
								</Text>
							</Stack>
							<Button
								as={Link}
								bgColor="#1877F2"
								color="white"
								borderRadius="base"
								fontSize="14px"
								fontWeight="normal"
								_hover={{ bgColor: "#166FE5" }}
								isExternal
								href="https://www.facebook.com/groups/userregistration"
								textDecor="none !important"
								alignSelf="flex-start"
								mt="auto"
							>
								{__("Join Group", "user-registration")}
							</Button>
						</Stack>
					</Stack>

					{/* X (Twitter) Card */}
					<Stack
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
						overflow="hidden"
						boxShadow="sm"
						h="100%"
					>
						<Box p="6" textAlign="center" position="relative">
							<Image src={x} />
						</Box>
						<Stack p="6" gap="3" flex="1" justify="space-between">
							<Stack gap="3">
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
									color="gray.800"
								>
									{__("X ( Twitter )", "user-registration")}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Follow us on X to get the latest news and updates about User Registration & Membership.",
										"user-registration"
									)}
								</Text>
							</Stack>
							<Button
								as={Link}
								bgColor="black"
								color="white"
								borderRadius="base"
								fontSize="14px"
								fontWeight="normal"
								_hover={{ bgColor: "#333" }}
								isExternal
								href="https://twitter.com/user_register"
								textDecor="none !important"
								alignSelf="flex-start"
								mt="auto"
							>
								{__("Follow", "user-registration")}
							</Button>
						</Stack>
					</Stack>

					{/* YouTube Card */}
					<Stack
						bgColor="white"
						borderRadius="base"
						border="1px"
						borderColor="gray.100"
						overflow="hidden"
						boxShadow="sm"
						h="100%"
					>
						<Box p="6" textAlign="center" position="relative">
							<Image src={youtube} />
						</Box>
						<Stack p="6" gap="3" flex="1" justify="space-between">
							<Stack gap="3">
								<Heading
									as="h3"
									size="sm"
									fontWeight="semibold"
									color="gray.800"
								>
									{__("YouTube", "user-registration")}
								</Heading>
								<Text fontSize="13px" color="gray.700">
									{__(
										"Subscribe to our YouTube channel, where we guide you on using User Registration & Membership features and add-ons.",
										"user-registration"
									)}
								</Text>
							</Stack>
							<Button
								as={Link}
								bgColor="#FF0000"
								color="white"
								borderRadius="base"
								fontSize="14px"
								fontWeight="normal"
								_hover={{ bgColor: "#CC0000" }}
								isExternal
								href="https://www.youtube.com/@UserRegistration"
								textDecor="none !important"
								alignSelf="flex-start"
								mt="auto"
							>
								{__("Subscribe", "user-registration")}
							</Button>
						</Stack>
					</Stack>
				</Grid>
			</Stack>
			<Stack gap="5">
				{/* <Stack
					p="6"
					gap="3"
					bgColor="white"
					borderRadius="base"
					border="1px"
					borderColor="gray.100"
				>
					<HStack gap="2">
						<URIcon.Team w="5" h="5" fill={PRIMARY_COLOR} />
						<Heading as="h3" size="sm" fontWeight="semibold">
							{__(
								"User Registration Community",
								"user-registration"
							)}
						</Heading>
					</HStack>
					<Text fontSize="13px" color="gray.700">
						{__(
							"Join our exclusive group and connect with fellow User Registration members. Ask questions, contribute to discussions, and share feedback!",
							"user-registration"
						)}
					</Text>
					<Link
						color="var(--chakra-colors-primary-500) !important"
						textDecor="underline"
						href={facebookGroup}
						isExternal
					>
						{__("Join our Facebook Group", "user-registration")}
					</Link>
				</Stack> */}
				{/* <Stack
					p="6"
					gap="3"
					bgColor="white"
					borderRadius="base"
					border="1px"
					borderColor="gray.100"
				>
					<HStack gap="2">
						<URIcon.DocsLines
							w="5"
							h="5"
							fill={PRIMARY_COLOR}
						/>
						<Heading as="h3" size="sm" fontWeight="semibold">
							{__("Getting Started", "user-registration")}
						</Heading>
					</HStack>
					<Text fontSize="13px" color="gray.700">
						{__(
							"Check our documentation for detailed information on User Registration features and how to use them.",
							"user-registration"
						)}
					</Text>
					<Link
						color="var(--chakra-colors-primary-500) !important"
						textDecor="underline"
						href={docURL}
						isExternal
					>
						{__("View Documentation", "user-registration")}
					</Link>
				</Stack> */}
				{/* <Stack
					p="6"
					gap="3"
					bgColor="white"
					borderRadius="base"
					border="1px"
					borderColor="gray.100"
				>
					<HStack gap="2">
						<URIcon.Headphones
							w="5"
							h="5"
							fill={PRIMARY_COLOR}
						/>
						<Heading as="h3" size="sm" fontWeight="semibold">
							{__("Support", "user-registration")}
						</Heading>
					</HStack>
					<Text fontSize="13px" color="gray.700">
						{__(
							"Submit a ticket for encountered issues and get help from our support team instantly.",
							"user-registration"
						)}
					</Text>
					<Link
						color="var(--chakra-colors-primary-500) !important"
						textDecor="underline"
						href={ticketUrl}
						isExternal
					>
						{__("Create a Ticket", "user-registration")}
					</Link>
				</Stack> */}
				<Stack
					p="6"
					gap="3"
					bgColor="white"
					borderRadius="base"
					border="1px"
					borderColor="gray.100"
				>
					<HStack gap="2">
						<BiBulb size={20} color={PRIMARY_COLOR} />
						<Heading as="h3" size="sm" fontWeight="semibold">
							{__("Feature Request", "user-registration")}
						</Heading>
					</HStack>
					<Text fontSize="13px" color="gray.700">
						{__(
							"Don't find a feature you're looking for? Suggest any features you think would enhance our product.",
							"user-registration"
						)}
					</Text>
					<Link
						color="var(--chakra-colors-primary-500) !important"
						textDecor="underline"
						href={featureRequestURL}
						isExternal
					>
						{__("Request a Feature", "user-registration")}
					</Link>
				</Stack>
				<Stack
					p="6"
					gap="3"
					bgColor="white"
					borderRadius="base"
					border="1px"
					borderColor="gray.100"
				>
					<HStack gap="2">
						<URIcon.Star w="5" h="5" fill={PRIMARY_COLOR} />
						<Heading as="h3" size="sm" fontWeight="semibold">
							{__("Submit a Review", "user-registration")}
						</Heading>
					</HStack>
					<Text fontSize="13px" color="gray.700">
						{__(
							"Please take a moment to give us a review. We appreciate honest feedback that'll help us improve our plugin.",
							"user-registration"
						)}
					</Text>
					<Link
						color="var(--chakra-colors-primary-500) !important"
						textDecor="underline"
						href={submitReviewUrl}
						isExternal
					>
						{__("Submit a Review", "user-registration")}
					</Link>
				</Stack>
				<Stack
					p="6"
					gap="3"
					bgColor="white"
					borderRadius="base"
					border="1px"
					borderColor="gray.100"
				>
					<HStack gap="2">
						<URIcon.Video w="5" h="5" fill={PRIMARY_COLOR} />
						<Heading as="h3" size="sm" fontWeight="semibold">
							{__("Video Tutorials", "user-registration")}
						</Heading>
					</HStack>
					<Text fontSize="13px" color="gray.700">
						{__(
							"Watch our step-by-step video tutorials that'll help you get the best out of User Registration & Membership's features.",
							"user-registration"
						)}
					</Text>
					<Link
						color="var(--chakra-colors-primary-500) !important"
						textDecor="underline"
						isExternal
						href="https://www.youtube.com/@UserRegistration"
					>
						{__("Watch Videos", "user-registration")}
					</Link>
				</Stack>
			</Stack>
		</Grid>
	);
};

export default Help;
