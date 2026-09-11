import { ArrowForwardIcon } from "@chakra-ui/icons";
import { Box, Button, Flex } from "@chakra-ui/react";
import { __ } from "@wordpress/i18n";
import { COLOR } from "../../tokens";
import StepHeader from "../StepHeader";

const IntroStep = ({ onStart }: { onStart: () => void }) => (
	<Box>
		<StepHeader
			title={__("Quick email delivery checkup", "user-registration")}
			description={__(
				"We'll scan your email settings, detect how mail leaves this site, then send a real test email and ask whether it arrived. Takes about two minutes.",
				"user-registration"
			)}
		/>
		<Flex justify="flex-end">
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
				onClick={onStart}
			>
				{__("Start the checkup", "user-registration")}
			</Button>
		</Flex>
	</Box>
);

export default IntroStep;
