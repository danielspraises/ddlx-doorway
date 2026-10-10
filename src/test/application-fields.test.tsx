import { render, screen, cleanup } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { ApplicationSection } from "@/components/ApplicationSection";

afterEach(cleanup);
describe("Conditional application fields", () => {
  it("shows brand details only when the applicant owns a brand", () => {
    const { rerender } = render(<ApplicationSection step={3} data={{ owns_brand: "no" }} errors={{}} onChange={() => {}} />);
    expect(screen.queryByLabelText(/Brand name/)).toBeNull();
    rerender(<ApplicationSection step={3} data={{ owns_brand: "yes" }} errors={{}} onChange={() => {}} />);
    expect(screen.getByLabelText(/Brand name/)).toBeInTheDocument();
  });
  it("shows registration details only for registered businesses", () => {
    const { rerender } = render(<ApplicationSection step={4} data={{ registered: "no" }} errors={{}} onChange={() => {}} />);
    expect(screen.queryByLabelText(/Registration number/)).toBeNull();
    rerender(<ApplicationSection step={4} data={{ registered: "yes" }} errors={{}} onChange={() => {}} />);
    expect(screen.getByLabelText(/Registration number/)).toBeInTheDocument();
  });
  it("shows specify fields for other business types and categories", () => {
    render(<ApplicationSection step={2} data={{ type: "Other", category: "Others" }} errors={{}} onChange={() => {}} />);
    expect(screen.getByLabelText(/Specify business type/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Specify category/)).toBeInTheDocument();
  });
});