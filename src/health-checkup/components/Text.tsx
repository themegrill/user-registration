import { Text as ChakraText, TextProps } from "@chakra-ui/react";

// Defaults to <div>: the plugin's `.user-registration-page p` rule (class+tag)
// outranks Chakra's utility classes, silently winning over any color/size set
// here. An explicit `as` still overrides this.
const Text = (props: TextProps) => <ChakraText as="div" {...props} />;

export default Text;
