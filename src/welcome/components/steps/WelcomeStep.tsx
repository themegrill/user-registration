import {
	Box,
	Checkbox,
	Heading,
	HStack,
	Input,
	Link,
	Radio,
	RadioGroup,
	SimpleGrid,
	Text,
	useColorModeValue,
	VStack
} from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import React, { useState } from "react";
import { MembershipSetupType } from "../../context/Gettingstartedcontext";
import { useStateValue } from "../../context/StateProvider";

interface MembershipOptionProps {
	value: MembershipSetupType;
	title: string;
}

const BRAND_COLOR = "#475BB2";
const BRAND_TINT = "#F5F7FD";

const MembershipOption: React.FC<MembershipOptionProps> = ({
	value,
	title
}) => {
	// gray.500 is about 4:1 on white, above the 3:1 non-text contrast minimum (WCAG 1.4.11).
	const defaultBorder = useColorModeValue("gray.500", "gray.600");
	const hoverBorder = useColorModeValue("gray.600", "gray.500");
	const selectedBg = useColorModeValue(BRAND_TINT, "whiteAlpha.100");
	const titleColor = useColorModeValue("gray.800", "white");

	// Card styles live on this wrapper: Chakra's Radio forwards most style props to its hidden input, not its root label.
	return (
		<Box
			borderRadius="6px"
			borderWidth="2px"
			borderColor={defaultBorder}
			transition="border-color 0.15s ease, background-color 0.15s ease"
			_hover={{ borderColor: hoverBorder }}
			sx={{
				"&:has(input:checked)": {
					borderColor: BRAND_COLOR,
					bg: selectedBg
				},
				"&:has(input:focus-visible)": {
					outline: `2px solid ${BRAND_COLOR}`,
					outlineOffset: "2px"
				},
				"& .chakra-radio": {
					display: "flex",
					alignItems: "center",
					w: "100%",
					minH: "52px",
					px: 4,
					py: 3,
					cursor: "pointer"
				},
				"& .chakra-radio__control": {
					borderColor: defaultBorder,
					"&[data-focus-visible]": { boxShadow: "none" }
				},
				"& .chakra-radio__control[data-checked]": {
					bg: BRAND_COLOR,
					borderColor: BRAND_COLOR
				},
				"& .chakra-radio__label": {
					ms: 3,
					fontWeight: 600,
					fontSize: "15px",
					lineHeight: "22px",
					color: titleColor
				}
			}}
		>
			<Radio value={value} colorScheme="blue">
				{title}
			</Radio>
		</Box>
	);
};

const WelcomeStep: React.FC = () => {
	const { state, dispatch } = useStateValue();
	const { membershipSetupType, allowTracking, adminEmail } = state;

	const [isEditingEmail, setIsEditingEmail] = useState(false);
	const [tempEmail, setTempEmail] = useState(adminEmail);

	const textColor = useColorModeValue("gray.800", "white");
	const mutedColor = useColorModeValue("gray.600", "gray.400");
	const linkColor = BRAND_COLOR;
	const inputBg = useColorModeValue("white", "gray.700");
	const inputBorder = useColorModeValue("gray.300", "gray.600");

	const handleMembershipChange = (value: MembershipSetupType) => {
		dispatch({ type: "SET_MEMBERSHIP_SETUP_TYPE", payload: value });
	};

	const handleTrackingChange = (e: React.ChangeEvent<HTMLInputElement>) => {
		dispatch({ type: "SET_ALLOW_TRACKING", payload: e.target.checked });
	};

	const handleChangeEmailClick = (e: React.MouseEvent) => {
		e.preventDefault();
		e.stopPropagation();
		setTempEmail(adminEmail);
		setIsEditingEmail(true);
	};

	const handleEmailChange = (e: React.ChangeEvent<HTMLInputElement>) => {
		setTempEmail(e.target.value);
	};

	const handleEmailBlur = () => {
		if (tempEmail && tempEmail.includes("@")) {
			dispatch({ type: "SET_ADMIN_EMAIL", payload: tempEmail });
		}
		setIsEditingEmail(false);
	};

	const handleEmailKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
		if (e.key === "Enter") {
			handleEmailBlur();
		} else if (e.key === "Escape") {
			setTempEmail(adminEmail);
			setIsEditingEmail(false);
		}
	};

	const emailForDisplay = adminEmail || "admin@example.com";

	const optionsToRender: MembershipOptionProps[] = [
		{
			value: "membership",
			title: __("Yes", "user-registration")
		},
		{
			value: "registration",
			title: __("Not now", "user-registration")
		}
	];

	return (
		<>
			<VStack align="flex-start" spacing={1} mb={8}>
				<Heading
					fontFamily="Inter"
					fontWeight={600}
					fontSize="21px"
					lineHeight="34px"
					letterSpacing="-0.01em"
					color={textColor}
				>
					{__(
						"Welcome to User Registration & Membership",
						"user-registration"
					)}
				</Heading>
				<Text color={mutedColor} fontSize="14px">
					{__(
						"Let's configure your site. You can change this anytime.",
						"user-registration"
					)}
				</Text>
			</VStack>

			<Box mb={8}>
				<Text
					id="urm-membership-question"
					fontWeight="600"
					color={textColor}
					fontSize="16px"
					lineHeight="24px"
					mb={4}
				>
					{__(
						"Do you want to offer membership plans on your site?",
						"user-registration"
					)}
				</Text>
				<RadioGroup
					value={membershipSetupType}
					onChange={handleMembershipChange as any}
					aria-labelledby="urm-membership-question"
				>
					<SimpleGrid columns={{ base: 1, sm: 2 }} spacing={4}>
						{optionsToRender.map((option) => (
							<MembershipOption
								key={option.value}
								value={option.value}
								title={option.title}
							/>
						))}
					</SimpleGrid>
				</RadioGroup>
			</Box>

			<Box>
				<HStack align="flex-start" spacing="10px">
					<Box flexShrink={0} pt="2px">
						<Checkbox
							isChecked={allowTracking}
							onChange={handleTrackingChange}
							colorScheme="blue"
							sx={{
								".chakra-checkbox__control[data-checked]": {
									bg: "#475BB2",
									borderColor: "#475BB2"
								}
							}}
						/>
					</Box>
					<Box>
						<Text
							fontSize="sm"
							color={mutedColor}
							lineHeight="20px"
						>
							{__(
								"Share anonymous usage data to improve URM, plus receive updates and offers.",
								"user-registration"
							)}
						</Text>
						<Box mt={1}>
							{isEditingEmail ? (
								<Input
									value={tempEmail}
									onChange={handleEmailChange}
									onBlur={handleEmailBlur}
									onKeyDown={handleEmailKeyDown}
									size="sm"
									width="220px"
									bg={inputBg}
									borderColor={inputBorder}
									borderRadius="4px"
									autoFocus
									placeholder="Enter email address"
									_focus={{
										borderColor: "#475BB2",
										boxShadow: "0 0 0 1px #475BB2"
									}}
								/>
							) : (
								<Text
									fontSize="sm"
									color={mutedColor}
									lineHeight="20px"
								>
									{__("Email:", "user-registration")}{" "}
									<Link
										color={linkColor}
										href={`mailto:${emailForDisplay}`}
									>
										{emailForDisplay}
									</Link>
									{" · "}
									<Link
										onClick={handleChangeEmailClick}
										cursor="pointer"
										_hover={{
											color: linkColor,
											textDecoration: "underline"
										}}
									>
										{__("Change", "user-registration")}
									</Link>
								</Text>
							)}
						</Box>
					</Box>
				</HStack>
			</Box>
		</>
	);
};

export default WelcomeStep;
