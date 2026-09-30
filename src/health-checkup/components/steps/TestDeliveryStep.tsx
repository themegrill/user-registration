import { Box, Flex, Spinner } from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { useEffect, useRef, useState } from "react";
import { FiAlertTriangle, FiCheck, FiChevronRight, FiX } from "react-icons/fi";
import { DeliveryOutcome, sendTestEmail } from "../../api/healthCheckupApi";
import { COLOR, TYPE } from "../../tokens";
import { LEGACY_BUTTON_OPT_OUT } from "../../utils/legacyButtonOptOut";
import StepHeader from "../StepHeader";
import Text from "../Text";

interface Choice {
	outcome: DeliveryOutcome;
	title: string;
	desc: string;
	icon: React.ReactNode;
	iconBg: string;
	iconColor: string;
}

interface TestDeliveryStepProps {
	onChoice: (outcome: DeliveryOutcome) => void;
	alreadySent: boolean;
	onSent: () => void;
	onSendFailed: (error: string) => void;
}

const TestDeliveryStep = ({ onChoice, alreadySent, onSent, onSendFailed }: TestDeliveryStepProps) => {
	const [isSending, setIsSending] = useState(!alreadySent);
	const [barWidth, setBarWidth] = useState("3%");
	const [pendingChoice, setPendingChoice] = useState<DeliveryOutcome | null>(null);
	const hasStarted = useRef(false);
	const adminEmail = window._UR_EMAIL_HEALTH_.adminEmail;

	useEffect(() => {
		if (hasStarted.current || alreadySent) {
			return;
		}
		hasStarted.current = true;

		requestAnimationFrame(() => requestAnimationFrame(() => setBarWidth("100%")));

		// A failed send already answers the question this step asks — don't send
		// someone hunting for a message that never left.
		sendTestEmail(adminEmail)
			.then(() => {
				setIsSending(false);
				onSent();
			})
			.catch((error: Error) => {
				onSent();
				onSendFailed(error.message);
			});
	}, [adminEmail, alreadySent, onSent, onSendFailed]);

	const choices: Choice[] = [
		{
			outcome: "arrived",
			title: __("Yes, it's in my inbox", "user-registration"),
			desc: __("It landed where it should", "user-registration"),
			icon: <FiCheck size={15} />,
			iconBg: "green.50",
			iconColor: "green.600",
		},
		{
			outcome: "spam",
			title: __("It's in the spam folder", "user-registration"),
			desc: __("It arrived, just filed in the wrong place", "user-registration"),
			icon: <FiAlertTriangle size={15} />,
			iconBg: "orange.50",
			iconColor: "orange.700",
		},
		{
			outcome: "none",
			title: __("No, nothing at all", "user-registration"),
			desc: __("Not in the inbox, not in spam", "user-registration"),
			icon: <FiX size={15} />,
			iconBg: "red.50",
			iconColor: "red.600",
		},
	];

	const handleChoice = (outcome: DeliveryOutcome) => {
		if (pendingChoice) {
			return;
		}
		setPendingChoice(outcome);
		setTimeout(() => onChoice(outcome), 550);
	};

	return (
		<Box>
			<StepHeader
				title={
					isSending
						? __("Sending your test email…", "user-registration")
						: __("Did the test email actually arrive?", "user-registration")
				}
				description={
					isSending ? (
						__("Hang tight, this only takes a second.", "user-registration")
					) : (
						<>
							{__("We just sent a test email to", "user-registration")}{" "}
							<Text as="b" color="inherit">
								{adminEmail}
							</Text>
							. {__("Check that inbox now, and check spam too.", "user-registration")}
						</>
					)
				}
			/>

			{isSending && (
				<Box pb="26px">
					<Box height="6px" borderRadius="6px" bg="gray.200" overflow="hidden">
						<Box
							height="100%"
							width={barWidth}
							bg="primary.500"
							borderRadius="6px"
							transition="width 950ms cubic-bezier(.4,0,.2,1)"
						/>
					</Box>
				</Box>
			)}

			{!isSending && (
				<>
					<Text fontSize={TYPE.body} lineHeight="1.62" color={COLOR.muted} mb="22px">
						{__(
							'A "sent successfully" message only means the site tried. What matters is whether it actually',
							"user-registration"
						)}{" "}
						<Text as="b" color={COLOR.body}>
							{__("landed", "user-registration")}
						</Text>
						.
					</Text>

					<Flex direction="column" gap="9px">
						{choices.map((choice) => (
							<Flex
								as="button"
								type="button"
								// Without this the legacy admin CSS gives every card after
								// the first a 6px left margin, so they stop lining up.
								className={LEGACY_BUTTON_OPT_OUT}
								key={choice.outcome}
								align="center"
								gap="13px"
								border="1px solid"
								borderColor="gray.200"
								bg="white"
								borderRadius="8px"
								p="13px 15px"
								textAlign="left"
								cursor={pendingChoice ? "default" : "pointer"}
								width="100%"
								_hover={pendingChoice ? {} : { borderColor: "primary.500", bg: "primary.50" }}
								onClick={(e) => {
									e.preventDefault();
									handleChoice(choice.outcome);
								}}
							>
								<Flex
									flexShrink={0}
									w="30px"
									h="30px"
									borderRadius="7px"
									align="center"
									justify="center"
									bg={choice.iconBg}
									color={choice.iconColor}
								>
									{pendingChoice === choice.outcome ? <Spinner size="sm" /> : choice.icon}
								</Flex>
								<Box flex="1" minW="0">
									<Text fontSize={TYPE.body} fontWeight="600" color={COLOR.title} display="block">
										{choice.title}
									</Text>
									<Text fontSize={TYPE.small} color={COLOR.muted} mt="2px">
										{choice.desc}
									</Text>
								</Box>
								<Box flexShrink={0} color="gray.400">
									<FiChevronRight size={16} />
								</Box>
							</Flex>
						))}
					</Flex>
				</>
			)}
		</Box>
	);
};

export default TestDeliveryStep;
