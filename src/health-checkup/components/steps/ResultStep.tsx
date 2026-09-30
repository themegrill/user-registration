import { Box, Button, Flex, Image, useToast } from "@chakra-ui/react";
import { __, sprintf } from "@wordpress/i18n";
import { ReactNode, useState } from "react";
import { ArrowForwardIcon } from "@chakra-ui/icons";
import { FiAlertTriangle, FiCheck, FiX } from "react-icons/fi";
import { HealthCheck, installSmartSmtp, SmartSmtpStatus, SmtpPluginInfo } from "../../api/healthCheckupApi";
import IssueList from "../IssueList";
import { COLOR, TYPE } from "../../tokens";
import RichText from "../RichText";
import StepHeader from "../StepHeader";
import Text from "../Text";

export type ResultVariant = "good" | "none" | "spam";

interface ResultStepProps {
	variant: ResultVariant;
	checks: HealthCheck[];
	onRunAgain: () => void;
	onOpenReport: () => void;
	smartSmtpStatus: SmartSmtpStatus;
	smtpPlugin: SmtpPluginInfo | null;
	/** The server's own error, when the test send failed outright. */
	sendError?: string | null;
}

// Reuse the most fundamental failing check's own wording rather than re-deriving
// it. Ordered by what stops mail earliest. SPF and DMARC are report-only, so
// they never appear here.
const CAUSE_PRIORITY = [
	"sending_enabled",
	"sending_route",
	"recent_failures",
	"smtp_connection",
	"from_address_valid",
	"from_effective",
	"from_effective_unknown",
	"from_alignment",
	"from_domain",
];

/**
 * What to say when the send reported no error and nothing in the scan failed
 * either. Not consulted when something did fail: the remedy list speaks then.
 */
const nonDeliveryExplanation = (
	checks: HealthCheck[],
	smtpPlugin: SmtpPluginInfo | null
): string => {
	const failing = new Map(
		checks
			.filter((check) => check.status === "error" || check.status === "warning")
			.map((check) => [check.key, check])
	);

	const causeKey = CAUSE_PRIORITY.find((key) => failing.has(key));
	const cause = causeKey ? failing.get(causeKey) : undefined;

	if (cause) {
		return cause.message;
	}

	if (smtpPlugin?.is_smartsmtp) {
		return __(
			"SmartSMTP is active and configured, but the test still didn't arrive. Its primary connection may need reconnecting.",
			"user-registration"
		);
	}

	if (smtpPlugin) {
		return sprintf(
			/* translators: %s: SMTP plugin name, e.g. "FluentSMTP" */
			__(
				"Your site sends through `%s`, but the test didn't arrive. The likeliest causes are that plugin's connection settings (host, port, or API key), or the receiving server rejecting the message.",
				"user-registration"
			),
			smtpPlugin.name
		);
	}

	return __(
		"An SMTP connection is configured, but the test didn't arrive. Check whichever service handles your outgoing mail for delivery errors.",
		"user-registration"
	);
};

/**
 * The recommended route out, below the scan's findings.
 *
 * Install/activate and configure stay two clicks: the first relabels, the second
 * opens SmartSMTP with Gmail preselected and its one-click setup offered — see
 * UR_Admin_Email_Checkup::smartsmtp_gmail_url(), and note the URL comes back from
 * the install response, so a fresh install lands in the same place.
 */
const SmartSmtpRecommendation = ({ status }: { status: SmartSmtpStatus }) => {
	const [isWorking, setIsWorking] = useState(false);
	const [localStatus, setLocalStatus] = useState<SmartSmtpStatus>(status);
	const [redirectUrl, setRedirectUrl] = useState<string | null>(null);
	const toast = useToast();

	const handleClick = async () => {
		if (localStatus === "active") {
			window.open(redirectUrl ?? window._UR_EMAIL_HEALTH_.smartSmtpUrl, "_blank", "noopener,noreferrer");
			return;
		}

		setIsWorking(true);
		try {
			const result = await installSmartSmtp();
			setRedirectUrl(result.redirect);
			setLocalStatus("active");
			toast({
				title:
					status === "not_installed"
						? __("SmartSMTP installed and activated", "user-registration")
						: __("SmartSMTP activated", "user-registration"),
				status: "success",
				duration: 5000,
				isClosable: true,
			});
		} catch (error) {
			toast({
				title: __("Couldn't set up SmartSMTP", "user-registration"),
				description: error instanceof Error ? error.message : undefined,
				status: "error",
				duration: 6000,
				isClosable: true,
			});
		} finally {
			setIsWorking(false);
		}
	};

	// Only the action varies. The description used to report SmartSMTP's status
	// ("it's active but never finished connecting"), which the button label already
	// says and which is no reason to pick it — so it now says what the admin gets:
	// a connection they can finish in one step.
	const copy = {
		not_installed: {
			action: __("Install & activate SmartSMTP", "user-registration"),
			loading: __("Installing…", "user-registration"),
		},
		inactive: {
			action: __("Activate SmartSMTP", "user-registration"),
			loading: __("Activating…", "user-registration"),
		},
		active: {
			action: __("Set up SmartSMTP", "user-registration"),
			loading: __("Opening…", "user-registration"),
		},
	}[localStatus];

	// Two columns on a tinted fill, no border: the claim and its detail read as a
	// sentence on the left, the action sits where the eye lands last. Sitting below
	// the scan's findings, a text link read as a footnote to the list — hence the
	// filled button — but centring it made a poster of it, and a border made it a
	// third framed panel on a screen that already has one. Stacks on narrow.
	return (
		<Flex
			bg="primary.50"
			borderRadius="8px"
			p="18px 20px"
			mt="16px"
			gap={{ base: "14px", md: "18px" }}
			align={{ base: "stretch", md: "center" }}
			justify="space-between"
			direction={{ base: "column", md: "row" }}
		>
			{/* Mark and copy are one group, so space-between opens a gap only before
			    the button. As three siblings the free space was shared out evenly and
			    left 80px stranded between the logo and the heading it belongs to. */}
			<Flex align="center" gap="18px" minW="0">
				<Image
					src={window._UR_EMAIL_HEALTH_.smartSmtpLogoUrl}
					alt=""
					// Sized to the copy beside it: heading plus two lines of detail is
					// about 58px tall, so the mark reads as its equal rather than as a
					// bullet in front of it.
					w="56px"
					h="56px"
					flexShrink={0}
					alignSelf={{ base: "flex-start", md: "center" }}
				/>

				{/* Capped width so the detail sets over two lines. Unbounded it ran as
				    a single line the width of the card, which read as a caption to the
				    heading rather than a paragraph of its own. */}
				<Box minW="0" maxW={{ base: "100%", md: "380px" }}>
					<Text fontSize={TYPE.subheading} fontWeight="600" color={COLOR.title} lineHeight="1.5">
						{__("SmartSMTP is the most reliable fix", "user-registration")}
					</Text>
					<Text fontSize={TYPE.body} color={COLOR.body} lineHeight="1.62" mt="5px">
						{__(
							"Connect your Gmail account in one click. No hosts or passwords to fill in, or add another SMTP provider.",
							"user-registration"
						)}
					</Text>
				</Box>
			</Flex>
			<Button
				flexShrink={0}
				bg={COLOR.link}
				color="white"
				_hover={{ bg: "#38488e" }}
				_active={{ bg: COLOR.link }}
				rightIcon={<ArrowForwardIcon />}
				fontSize={{ base: "sm", md: "md" }}
				fontWeight="500"
				px={{ base: 4, md: 6 }}
				py={2}
				onClick={handleClick}
				isLoading={isWorking}
				loadingText={copy.loading}
			>
				{copy.action}
			</Button>
		</Flex>
	);
};

/**
 * Findings the live test has already answered. "The sender can't be confirmed"
 * describes the scan's limits, not the site: once a message has arrived, that
 * uncertainty is settled.
 */
const SUPERSEDED_BY_DELIVERY = ["from_effective_unknown"];

/** Open findings worth showing once the message did arrive, inbox or spam. */
const issuesWorthShowingAfterDelivery = (checks: HealthCheck[]) =>
	checks.filter(
		(check) =>
			(check.status === "error" || check.status === "warning") &&
			!SUPERSEDED_BY_DELIVERY.includes(check.key)
	);

/**
 * Failing checks with a remedy: a sentence, or a link to the screen that owns it.
 * Settings checks carry only the link, so filtering on `fix` alone drops them.
 */
const remediableChecks = (checks: HealthCheck[]) =>
	checks.filter(
		(check) => (check.status === "error" || check.status === "warning") && (check.fix || check.action)
	);

/** A labelled paragraph. */
const Advice = ({ label, children }: { label: string; children: ReactNode }) => (
	<Box mt="15px">
		<Text fontSize={TYPE.body} fontWeight="600" color={COLOR.title}>
			{label}
		</Text>
		<Text fontSize={TYPE.body} color={COLOR.body} mt="4px" lineHeight="1.62">
			{children}
		</Text>
	</Box>
);

/** The last resort. */
const SupportFootnote = ({ children }: { children: ReactNode }) => (
	<Text
		fontSize={TYPE.small}
		color={COLOR.muted}
		mt="18px"
		pt="15px"
		borderTop="1px solid"
		borderColor="gray.200"
		lineHeight="1.55"
	>
		{children}
	</Text>
);

type Tone = "green" | "orange" | "red";

const TONE_ICON: Record<Tone, ReactNode> = {
	green: <FiCheck size={16} />,
	orange: <FiAlertTriangle size={16} />,
	red: <FiX size={16} />,
};

const TONE_ICON_COLOR: Record<Tone, string> = {
	green: "green.600",
	orange: "orange.700",
	red: "red.600",
};

// One frame for all three outcomes, so they read as the same screen with a
// different verdict.
const ResultFrame = ({
	tone,
	title,
	subtitle,
	bannerFooter,
	body,
	onRunAgain,
	onOpenReport,
}: {
	tone: Tone;
	title: string;
	subtitle: ReactNode;
	bannerFooter?: ReactNode;
	/** Everything the outcome has to say, below the headline. */
	body?: ReactNode;
	onRunAgain: () => void;
	onOpenReport: () => void;
}) => (
	<Box>
		{/* No panel of its own: this already sits inside the wizard's card. */}
		<StepHeader
			title={title}
			description={subtitle}
			icon={
				/* Tinted rather than filled: white on any of these hues is too
				   low-contrast, and the soft disc reads as a status marker. */
				<Flex
					flexShrink={0}
					w="32px"
					h="32px"
					borderRadius="full"
					bg={`${tone}.50`}
					align="center"
					justify="center"
					color={TONE_ICON_COLOR[tone]}
				>
					{TONE_ICON[tone]}
				</Flex>
			}
		/>
		{bannerFooter}
		{body}

		{/* The run ends here, so Finish takes the primary slot. */}
		<Flex gap="10px" mt="24px" wrap="wrap" justify="flex-end">
			<Button variant="outline" fontSize="sm" fontWeight="500" onClick={onOpenReport}>
				{__("Send report", "user-registration")}
			</Button>
			<Button variant="outline" fontSize="sm" fontWeight="500" onClick={onRunAgain}>
				{__("Run again", "user-registration")}
			</Button>
			<Button
				as="a"
				href={window._UR_EMAIL_HEALTH_.exitUrl}
				bg={COLOR.link}
				color="white"
				_hover={{ bg: "#38488e" }}
				_active={{ bg: COLOR.link }}
				fontSize={{ base: "sm", md: "md" }}
				fontWeight="500"
				px={{ base: 2, md: 4 }}
				py={2}
			>
				{__("Finish", "user-registration")}
			</Button>
		</Flex>
	</Box>
);

const ResultStep = ({ variant, checks, onRunAgain, onOpenReport, smartSmtpStatus, smtpPlugin, sendError }: ResultStepProps) => {
	const openIssues = issuesWorthShowingAfterDelivery(checks);

	if (variant === "good") {
		return (
			<ResultFrame
				tone="green"
				title={__("Your test email arrived", "user-registration")}
				// Says plainly that the leftovers didn't stop it, or a green headline
				// over "3 things to fix" reads as a contradiction.
				subtitle={
					openIssues.length > 0
						? __(
								"Delivery works from this site and the message reached the inbox. Nothing below stopped it arriving, but a few settings are still worth a look.",
								"user-registration"
							)
						: __(
								"Delivery works from this site and every check passed. There is nothing left to fix.",
								"user-registration"
							)
				}
				onRunAgain={onRunAgain}
				onOpenReport={onOpenReport}
				body={
					openIssues.length > 0 ? (
						<IssueList issues={openIssues} />
					) : (
						<SupportFootnote>
							{__(
								"Registration emails should reach your users the same way. Run this again any time you change your email or hosting setup.",
								"user-registration"
							)}
						</SupportFootnote>
					)
				}
			/>
		);
	}

	// No SPF, DMARC or DKIM by name: those are the receiving provider's levers.
	if (variant === "spam") {
		return (
			<ResultFrame
				tone="orange"
				title={__("It arrived, but landed in spam", "user-registration")}
				subtitle={__(
					"Your site sent it fine. The receiving mailbox is what put it in spam.",
					"user-registration"
				)}
				onRunAgain={onRunAgain}
				onOpenReport={onOpenReport}
				body={
					<>
						{/* The label is the reassurance: it separates one message in spam
						    from every message in spam. */}
						<Advice label={__("If it keeps happening", "user-registration")}>
							{__(
								"One email in spam is normal. Domains that have only recently started sending get filtered more until they build up a history.",
								"user-registration"
							)}
						</Advice>

						<IssueList issues={openIssues} />

						<SupportFootnote>
							{__(
								"Still landing in spam? Send the issue report to our support team and get help.",
								"user-registration"
							)}
						</SupportFootnote>
					</>
				}
			/>
		);
	}

	const suggestions = remediableChecks(checks);

	// One panel reading as two routes out: fix these, or let SmartSMTP do it.
	return (
		<ResultFrame
			tone="red"
			title={__("The test email didn't arrive", "user-registration")}
			subtitle={
				sendError
					? __(
							"Your site couldn't send it at all. The attempt failed before the message left the server.",
							"user-registration"
						)
					: suggestions.length > 0
						? __(
								"Your site reported no error, so the message was dropped or rejected after it left.",
								"user-registration"
							)
						: <RichText text={nonDeliveryExplanation(checks, smtpPlugin)} />
			}
			onRunAgain={onRunAgain}
			onOpenReport={onOpenReport}
			body={
				<>
					{/* The findings lead. They are what the scan established about this
					    site; putting a product recommendation above them put the pitch
					    ahead of the diagnosis. */}
					{suggestions.length > 0 && (
						<IssueList
							issues={suggestions}
							label={__("What the scan found", "user-registration")}
						/>
					)}

					<SmartSmtpRecommendation status={smartSmtpStatus} />

					{/* The generic pointer is dropped; a real reported error is quoted. */}
					{sendError && !sendError.includes("ur_mail_logs") && (
						<Text fontSize={TYPE.body} color={COLOR.body} mt="10px" lineHeight="1.6">
							{__("Your mail server reported:", "user-registration")}{" "}
							<Text as="code" fontFamily="mono" fontSize="0.92em">
								{sendError}
							</Text>
						</Text>
					)}

					<SupportFootnote>
						{__(
							"Still stuck? Send the issue report to our support team and get help.",
							"user-registration"
						)}
					</SupportFootnote>
				</>
			}
		/>
	);
};

export default ResultStep;
