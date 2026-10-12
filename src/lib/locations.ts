import { Country, State } from "country-state-city";
import { NIGERIAN_STATES } from "@/lib/application";

export const ALL_COUNTRIES = Country.getAllCountries().map(country => country.name).sort();
export function regionsForCountry(name: string): string[] {
  if (name === "Nigeria") return NIGERIAN_STATES;
  const country = Country.getAllCountries().find(country => country.name === name);
  return country ? [...new Set(State.getStatesOfCountry(country.isoCode).map(state => state.name))].sort() : [];
}