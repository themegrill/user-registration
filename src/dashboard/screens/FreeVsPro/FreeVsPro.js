/**
 *  External Dependencies
 */
import React, { useState, useEffect } from "react";
import {
	TableContainer,
	Table,
	Thead,
	Tbody,
	Th,
	Td,
	Tr,
	Image,
	Stack,
	Box,
	Text,
	Button,
	Link
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";

/**
 *  Internal Dependencies
 */
import check from "./images/check.webp";
import close from "./images/close.webp";
import { Lock } from "../../components/Icon/Icon";
import { getAllModules } from "../Modules/components/modules-api";
import { urUtmUrl } from "../../../utils/utm";

const FreeVsPro = () => {
	const [contentsLoaded, setContentsLoaded] = useState(false);
	const sharedFeatures = [
		"user-registration-membership",
		"user-registration-content-restriction",
		"user-registration-payment-history"
	];
	/* global _UR_DASHBOARD_ */
	const { upgradeURL } =
		typeof _UR_DASHBOARD_ !== "undefined" && _UR_DASHBOARD_;
	const [tableContents, setTableContents] = useState([
		{
			type: "features",
			title: __("Features", "user-registration"),
			contents: [
				{
					title: __(
						"Unlimited Registration Forms",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __(
						"Unlimited User Registrations",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __("Built-in Login Forms", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("Login Form Templates", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("AJAX Login", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __(
						"Replace Default WordPress Login",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __("Logout Confirmation", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __(
						"4 Registration Approval Methods",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __("Individual User Profiles", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("Account Page Layouts", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __(
						"User Registration & Membership Analytics widget",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __(
						"Customizable Email Notifications",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __(
						"User Redirection After Registration",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __(
						"Google reCAPTCHA v2 and V3",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __("HCaptcha", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("Cloudflare Turnstile", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("Import/Export Forms", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("Export Users", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __(
						"Erase Data After Uninstallation",
						"user-registration"
					),
					free: true,
					pro: true
				},
				{
					title: __("Strong Password", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("GDPR Compliance", "user-registration"),
					free: true,
					pro: true
				},
				{
					title: __("Dashboard Analytics", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __(
						"Admin Approval after Email Confirmation",
						"user-registration"
					),
					free: false,
					pro: true
				},
				{
					title: __("Login Form Field Icons", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Password-less Login", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Multiple Login Prevention", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Whitelisted Domains", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __(
						"Registration And Login Popups",
						"user-registration"
					),
					free: false,
					pro: true
				},
				{
					title: __("Role Based Redirection", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Keyboard Friendly Forms", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Form Reset Button", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Honeypot Spam Protection", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __(
						"Field Mapping with External Plugins",
						"user-registration"
					),
					free: false,
					pro: true
				},
				{
					title: __("Unique Field Validation", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __("Auto Populate Form Fields", "user-registration"),
					free: false,
					pro: true
				},
				{
					title: __(
						"Auto Logout After Inactivity",
						"user-registration"
					),
					free: false,
					pro: true
				},
				{
					title: __(
						"Send Form Data to External URL After Registration",
						"user-registration"
					),
					free: false,
					pro: true
				}
			]
		},
		{
			type: "addons",
			title: __("Addons", "user-registration"),
			contents: []
		}
	]);

	useEffect(() => {
		if (!contentsLoaded) {
			const tableContentsRef = [...tableContents];

			getAllModules()
				.then((data) => {
					if (data.success) {
						tableContentsRef.map((tableContent, key) => {
							if (tableContent.type === "features") {
								data.modules_lists.map((module) => {
									if (module.type == "feature") {
										let isFree =
											module.plan.includes("free");
										let isPro =
											!isFree ||
											sharedFeatures.includes(
												module.slug
											);

										tableContent.contents.push({
											title: module.title,
											free: isFree,
											pro: isPro
										});
									}
								});
								tableContentsRef[key] = tableContent;
							}
							if (tableContent.type === "addons") {
								data.modules_lists.map((module) => {
									if (module.type == "addon") {
										tableContent.contents = [
											...tableContent.contents,
											{
												title: module.title,
												free: false,
												pro: true
											}
										];
									}
								});
								tableContentsRef[key] = tableContent;
							}
						});
						setTableContents(tableContentsRef);
					}
				})
				.catch((e) => {
					toast({
						title: e.message,
						status: "error",
						duration: 3000
					});
				});
			setContentsLoaded(true);
		}
	}, [contentsLoaded, tableContents]);

	return (
		<Stack direction="column" gap="10px">
			<TableContainer my="8" mx="6">
				{tableContents.map((tableContent) => (
					<Table
						variant="simple"
						fontSize="14px"
						key={tableContent.type}
					>
						<Thead bgColor="#475bb2">
							<Tr border="1px solid #EDF2F7" alignItems="center">
								<Th w="50%" color="white">
									{tableContent.title}
								</Th>
								<Th w="25%" color="white">
									{__("Free", "user-registration")}
								</Th>
								<Th w="25%" color="white">
									{__("Pro", "user-registration")}
								</Th>
							</Tr>
						</Thead>
						<Tbody>
							{tableContent.contents.map((rowContent) => (
								<Tr
									border="1px solid #EDF2F7"
									alignItems="center"
									key={rowContent.title}
								>
									<Td>{rowContent.title}</Td>
									<Td>
										{rowContent.free ? (
											<Image
												w="16px"
												h="16px"
												src={check}
											/>
										) : (
											<Image
												w="16px"
												h="16px"
												src={close}
											/>
										)}
									</Td>
									<Td>
										{rowContent.pro ? (
											<Image
												w="16px"
												h="16px"
												src={check}
											/>
										) : (
											<Image
												w="16px"
												h="16px"
												src={close}
											/>
										)}
									</Td>
								</Tr>
							))}
						</Tbody>
					</Table>
				))}
			</TableContainer>
			<Stack
				gap="16px"
				direction="column"
				alignItems="center"
				bgColor="#F1F5FE"
				padding="32px 0px"
				borderRadius="4px"
				my="8"
				mx="6"
			>
				<Lock h={"70px"} w={"80px"} />
				<Text fontSize="18px" lineHeight="24px" fontWeight="700">
					{__("Upgrade Now", "user-registration")}
				</Text>
				<Text
					fontSize="14px"
					lineHeight="24px"
					fontWeight="400"
					padding="10px 50px"
					color="#6B6B6B"
				>
					{__(
						"Access all premium addons, features and upcoming updates right away by upgrading to the Pro version.",
						"user-registration"
					)}
				</Text>
				<Button
					as={Link}
					colorScheme="primary"
					href={urUtmUrl(
						upgradeURL || "https://wpuserregistration.com/upgrade/",
						{
							source: "dashboard-free-vs-pro",
							medium: "button"
						}
					)}
					color="white !important"
					textDecor="none !important"
					isExternal
					padding="10px 16px"
					borderRadius="3px"
				>
					{__(
						"Get User Registration & Membership Pro Now",
						"user-registration"
					)}
				</Button>
			</Stack>
		</Stack>
	);
};

export default FreeVsPro;
