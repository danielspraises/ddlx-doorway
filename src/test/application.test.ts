import { describe, expect, it } from "vitest";
import { validate, summaryRows } from "@/lib/application";
import { section } from "@/lib/application-data";

describe("Seller application", () => {
  it("requires personal details and a Nigerian phone", () => {
    expect(validate(1, {}).first_name).toBeTruthy();
    expect(validate(1, { phone: "123" }).phone).toBeTruthy();
  });
  it("requires two socials and conditional brand details", () => {
    expect(validate(3, { store_name: "Test", instagram: "@test", owns_brand: "no" }).socials).toBeTruthy();
    expect(validate(3, { store_name: "Test", instagram: "@test", facebook: "test", owns_brand: "no" })).toEqual({});
    expect(validate(3, { store_name: "Test", instagram: "@test", facebook: "test", owns_brand: "yes" }).brand_name).toBeTruthy();
  });
  it("only requires registration details for registered businesses", () => {
    expect(validate(4, { registered: "no" })).toEqual({});
    expect(validate(4, { registered: "yes" }).reg_number).toBeTruthy();
    expect(summaryRows(4, { registered: "no", reg_number: "hidden" })).toEqual([["Registered", "No"]]);
  });
  it("reads only string values from saved sections", () => {
    expect(section({ name: "Store", ignored: false })).toEqual({ name: "Store" });
    expect(section(null)).toEqual({});
  });
});