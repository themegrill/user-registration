import { ArrowBackIcon, ArrowForwardIcon } from "@chakra-ui/icons";
import { Box, Button, Collapse, Flex, Link, useDisclosure, useToast } from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { ReactNode, useState } from "react";
import {
	FiAlertCircle,
	FiAlertTriangle,
	FiCheck,
	FiChevronDown,
	FiExternalLink,
	FiHelpCircle,
	FiSlash,
} from "react-icons/fi";
import {
	activateSmtpPlugin,
	CheckAction,
	CheckSection,
	CheckStatus,
	HealthCheck,
	installSmartSmtp,
} from "../api/healthCheckupApi";
import { COLOR, TYPE } from "../tokens";
import { LEGACY_BUTTON_OPT_OUT } from "../utils/legacyButtonOptOut";
import RichText from "./RichText";
import StepHeader from "./StepHeader";
import Text from "./Text";

/**
 * Split a message after its first sentence, so a warning can lead with its gist.
 * A naive split on ". " breaks on the dotted values these messages are full of
 * (`themegrill.com`, `mail()`), so only a stop outside a `code` span counts.
 */
export const splitFirstSentence = (text: string): { lead: string; rest: string } => {
	let inCode = false;

	for (let index = 0; index < text.length; index++) {
		const character = text[index];

		if ("`" === character) {
			inCode = !inCode;
			continue;
		}

		if (inCode || !".!?".includes(character)) {
			continue;
		}

		const after = text.slice(index + 1);
		const following = after.match(/^\s+(\S)/);

		if (!following) {
			continue;
		}

		// Lowercase next means the stop was part of a value, not a sentence end.
		const next = following[1];

		if (next !== next.toUpperCase()) {
			continue;
		}

		return { lead: text.slice(0, index + 1), rest: after.trim() };
	}

	return { lead: text, rest: "" };
};

// Resolves a failing check in place; on success the scan re-runs so the row
// flips to Pass.
const CheckActionLink = ({ action, onResolved }: { action: CheckAction; onResolved: () => void }) => {
	const [isWorking, setIsWorking] = useState(false);
	const toast = useToast();

	if (action.type === "link") {
		return (
			<Link
				href={action.url}
				isExternal
				display="inline-flex"
				alignItems="center"
				gap="5px"
				mt="8px"
				fontSize={TYPE.body}
				fontWeight="500"
				color={COLOR.link}
				_hover={{ textDecoration: "underline" }}
			>
				{action.label}
				<FiExternalLink size={13} />
			</Link>
		);
	}

	const handleClick = async () => {
		setIsWorking(true);
		try {
			if (action.type === "activate") {
				await activateSmtpPlugin(action.plugin ?? "");
			} else {
				await installSmartSmtp();
			}
			onResolved();
		} catch (error) {
			toast({
				title:
					action.type === "activate"
						? __("Couldn't activate the plugin", "user-registration")
						: __("Couldn't set up SmartSMTP", "user-registration"),
				description: error instanceof Error ? error.message : undefined,
				status: "error",
				duration: 6000,
				isClosable: true,
			});
			setIsWorking(false);
		}
	};

	return (
		<Button
			variant="link"
			colorScheme="primary"
			fontSize={TYPE.body}
			fontWeight="500"
			mt="8px"
			onClick={handleClick}
			isLoading={isWorking}
			loadingText={
				action.type === "activate"
					? __("Activating…", "user-registration")
					: __("Installing…", "user-registration")
			}
		>
			{action.label}
		</Button>
	);
};

interface RowStyle {
	icon: ReactNode;
	badgeLabel: string;
	borderColor: string;
	bg: string;
	iconColor: string;
	titleColor: string;
	messageColor: string;
	badgeColor: string;
	badgeBg: string;
	badgeBorderColor: string;
}

// Severity is carried by the icon, badge and tint, never by the text colour:
// coloured copy on a coloured panel reads worse and skews the hierarchy.
const rowStyles: Record<CheckStatus, RowStyle> = {
	pass: {
		icon: <FiCheck size={18} />,
		badgeLabel: __("Pass", "user-registration"),
		borderColor: "gray.200",
		bg: "white",
		iconColor: "green.600",
		titleColor: "inherit",
		messageColor: "gray.600",
		badgeColor: "green.700",
		badgeBg: "green.50",
		badgeBorderColor: "green.200",
	},
	error: {
		icon: <FiAlertTriangle size={18} />,
		badgeLabel: __("Will fail", "user-registration"),
		borderColor: "red.200",
		bg: "red.50",
		iconColor: "red.600",
		titleColor: "gray.800",
		messageColor: "gray.600",
		badgeColor: "red.600",
		badgeBg: "white",
		badgeBorderColor: "red.200",
	},
	warning: {
		icon: <FiAlertCircle size={18} />,
		badgeLabel: __("May fail", "user-registration"),
		borderColor: "orange.200",
		bg: "orange.50",
		iconColor: "orange.700",
		titleColor: "gray.800",
		messageColor: "gray.600",
		badgeColor: "orange.700",
		badgeBg: "white",
		badgeBorderColor: "orange.200",
	},
	unknown: {
		icon: <FiHelpCircle size={18} />,
		badgeLabel: __("Can't tell", "user-registration"),
		borderColor: "gray.200",
		bg: "gray.50",
		iconColor: "gray.500",
		titleColor: "gray.700",
		messageColor: "gray.600",
		badgeColor: "gray.600",
		badgeBg: "white",
		badgeBorderColor: "gray.300",
	},
	blocked: {
		icon: <FiSlash size={18} />,
		badgeLabel: __("Won't send", "user-registration"),
		borderColor: "gray.200",
		bg: "gray.50",
		iconColor: "gray.500",
		titleColor: "gray.700",
		messageColor: "gray.600",
		badgeColor: "gray.600",
		badgeBg: "white",
		badgeBorderColor: "gray.300",
	},
};

const CheckRow = ({
	check,
	index,
	showFix,
	onResolved,
}: {
	check: HealthCheck;
	index: number;
	showFix: boolean;
	onResolved: () => void;
}) => {
	const style = rowStyles[check.status] ?? rowStyles.pass;
	const isActionable = check.status === "error" || check.status === "warning";
	const { lead, rest } = splitFirstSentence(check.message);
	const { isOpen, onToggle } = useDisclosure();

	// Only "may fail" folds, and only when there is something to fold: an error
	// has to stay readable and a one-sentence warning is short already.
	const isCollapsible = "warning" === check.status && "" !== rest;

	const badge = (
		<Text
			fontSize={TYPE.badge}
			fontWeight="700"
			letterSpacing="0.03em"
			textTransform="uppercase"
			px="7px"
			py="2px"
			borderRadius="100px"
			flexShrink={0}
			color={style.badgeColor}
			bg={style.badgeBg}
			border="1px solid"
			borderColor={style.badgeBorderColor}
		>
			{style.badgeLabel}
		</Text>
	);

	const fixAndAction = (
		<>
			{showFix && check.fix && (
				<Text fontSize={TYPE.body} color={style.titleColor} mt="7px" lineHeight="1.6">
					<RichText text={check.fix} />
				</Text>
			)}
			{check.action && (
				<Box>
					<CheckActionLink action={check.action} onResolved={onResolved} />
				</Box>
			)}
		</>
	);

	return (
		<Flex
			border="1px solid"
			borderColor={style.borderColor}
			bg={style.bg}
			borderRadius="8px"
			p="13px 14px"
			gap="11px"
			align="flex-start"
			opacity={0}
			animation={`rowIn 360ms ease ${index * 55}ms forwards`}
			sx={{
				"@keyframes rowIn": {
					to: { opacity: 1, transform: "none" },
				},
			}}
		>
			<Box flexShrink={0} color={style.iconColor} mt="1px">
				{style.icon}
			</Box>
			<Box flex="1" minW="0">
				<Flex
					align="center"
					justify="space-between"
					gap="10px"
					{...(isCollapsible
						? {
								as: "button",
								type: "button",
								className: LEGACY_BUTTON_OPT_OUT,
								onClick: onToggle,
								"aria-expanded": isOpen,
								width: "100%",
								textAlign: "left",
								cursor: "pointer",
							}
						: {})}
				>
					<Text fontSize={TYPE.body} fontWeight="600" color={style.titleColor}>
						{check.title}
					</Text>
					<Flex align="center" gap="7px" flexShrink={0}>
						{badge}
						{isCollapsible && (
							<Box
								display="flex"
								color={style.badgeColor}
								transform={isOpen ? "rotate(180deg)" : undefined}
								transition="transform 180ms ease"
							>
								<FiChevronDown size={15} />
							</Box>
						)}
					</Flex>
				</Flex>

				<Text fontSize={TYPE.body} color={style.messageColor} mt="4px" lineHeight="1.6">
					<RichText text={isCollapsible ? lead : check.message} />
				</Text>

				{isCollapsible ? (
					<Collapse in={isOpen} animateOpacity>
						<Text fontSize={TYPE.body} color={style.messageColor} mt="7px" lineHeight="1.6">
							<RichText text={rest} />
						</Text>
						{fixAndAction}
					</Collapse>
				) : (
					isActionable && fixAndAction
				)}
			</Box>
		</Flex>
	);
};

export interface ScanScreenProps {
	loadingHeading: string;
	heading: string;
	loadingBlurb: string;
	/** The one section this step presents, or null while the scan is in flight. */
	section: CheckSection | null;
	/**
	 * Whether a failing row carries its remedy inline. Plugin settings do; mail
	 * delivery's remedies are recommendations the result screen makes instead.
	 */
	showFixes?: boolean;
	isLoading: boolean;
	error: string;
	onNext: () => void;
	onBack?: () => void;
	/** Omitted where a report wouldn't help yet, e.g. before the delivery checks. */
	onResolved: () => void;
}

/**
 * The frame both scan steps render in. No verdict banner: a headline asserting
 * whether mail will arrive sat above findings that often couldn't support it.
 * The verdict still leads the support report.
 */
const ScanScreen = ({
	loadingHeading,
	heading,
	loadingBlurb,
	section,
	showFixes = true,
	isLoading,
	error,
	onNext,
	onBack,
	onResolved,
}: ScanScreenProps) => (
	<Box>
		{/* The section's description is written server-side. */}
		<StepHeader
			title={isLoading ? loadingHeading : heading}
			description={isLoading ? loadingBlurb : (section?.description ?? "")}
		/>

		{isLoading && (
			<Box pb="26px">
				<Box height="6px" borderRadius="6px" bg="gray.200" overflow="hidden">
					<Box
						height="100%"
						width="100%"
						bg="primary.500"
						borderRadius="6px"
						sx={{
							"@keyframes barIn": { from: { width: "3%" }, to: { width: "100%" } },
						}}
						animation="barIn 950ms cubic-bezier(.4,0,.2,1)"
					/>
				</Box>
			</Box>
		)}

		{error && (
			<Text color="red.600" fontSize="13.5px" mb="16px">
				{error}
			</Text>
		)}

		{!isLoading && !error && (
			<>
				<Flex direction="column" gap="8px" mb="26px">
					{(section?.checks ?? []).map((check, index) => (
						<CheckRow
							key={check.key}
							check={check}
							index={index}
							showFix={showFixes}
							onResolved={onResolved}
						/>
					))}
				</Flex>

				<Flex
					gap="10px"
					mt="4px"
					wrap="wrap"
					align="center"
					justify={onBack ? "space-between" : "flex-end"}
				>
					{onBack && (
						<Link
							display="flex"
							alignItems="center"
							fontSize="sm"
							color={COLOR.body}
							_hover={{ color: COLOR.title, textDecoration: "none" }}
							cursor="pointer"
							onClick={onBack}
						>
							<ArrowBackIcon mr={1} />
							{__("Back", "user-registration")}
						</Link>
					)}
					{/* No support report here. Sent before the live test it would reach
					    support carrying only what the scan guessed, with no outcome — and
					    it showed on step 3 but not step 2, so it read as something the
					    admin had missed. The result screen offers it instead. */}
					<Flex gap="10px" wrap="wrap" justify="flex-end">
						<Button
							bg={COLOR.link}
							color="white"
							_hover={{ bg: "#38488e" }}
							_active={{ bg: COLOR.link }}
							rightIcon={<ArrowForwardIcon />}
							fontSize={{ base: "sm", md: "md" }}
							fontWeight="500"
							px={{ base: 2, md: 4 }}
							py={2}
							onClick={onNext}
						>
							{__("Next", "user-registration")}
						</Button>
					</Flex>
				</Flex>
			</>
		)}
	</Box>
);

export default ScanScreen;
